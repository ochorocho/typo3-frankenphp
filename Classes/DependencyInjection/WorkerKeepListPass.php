<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\DependencyInjection;

use Ochorocho\FrankenPhp\Worker\KeepList;
use Ochorocho\FrankenPhp\Worker\WorkerConfiguration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Classifies every shared service as "keep across worker requests" or
 * "discard per request" and stores the result as container parameters.
 *
 * Order of rules:
 *  1. Intrinsic: readonly classes and readonly-property-only classes are kept.
 *  2. Overrides, discard first: KeepList, the frankenphp.* tags, the packages'
 *     Configuration/FrankenPhpWorker.php. An explicit discard beats any keep
 *     or pin, except inside the curated pins' closure (boot-populated).
 *  3. Closure: a kept service holding a discarded, non-shared or RequestId
 *     dependency is demoted. Pins invert this and keep their closure; a pin
 *     that reaches such a dependency is a reported conflict.
 *
 * Runs at TYPE_AFTER_REMOVING: inlining is done, the reference graph final.
 */
final class WorkerKeepListPass implements CompilerPassInterface
{
    public const string PARAMETER_KEEP = 'frankenphp.worker.keep';
    public const string PARAMETER_REPORT = 'frankenphp.worker.report';
    public const string PARAMETER_UNMATCHED = 'frankenphp.worker.unmatched';
    public const string TAG_KEEP = 'frankenphp.keep';
    public const string TAG_DISCARD = 'frankenphp.discard';
    public const string REQUEST_ID_SERVICE = ServiceGraph::REQUEST_ID_SERVICE;
    public const string CATEGORY_KEEP = Classification::KEEP;
    public const string CATEGORY_DISCARD = Classification::DISCARD;

    /**
     * @param list<string> $pinnedServices
     * @param list<string> $softServices
     * @param list<string> $discardServices
     * @param list<string> $discardPatterns
     * @param array<string, string> $keepIdPatterns pattern => reason
     */
    public function __construct(
        private readonly ClassStateAnalyzer $analyzer = new ClassStateAnalyzer(),
        private readonly array $pinnedServices = KeepList::PINNED,
        private readonly array $softServices = KeepList::SOFT,
        private readonly array $discardServices = KeepList::DISCARD,
        private readonly array $discardPatterns = KeepList::DISCARD_PATTERNS,
        private readonly array $keepIdPatterns = KeepList::KEEP_ID_PATTERNS,
        private readonly WorkerConfiguration $configuration = new WorkerConfiguration(),
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $graph = new ServiceGraph($container, $container->getCompiler()->getServiceReferenceGraph(), $this->analyzer);
        $result = new Classification();

        $this->classify($graph, $result);
        $this->protectCuratedPins($graph, $result);
        $this->applyDiscards($graph, $result);
        $this->applyPatterns($result);
        $this->applyKeeps($graph, $result);
        $this->applyPins($graph, $result);
        $this->demoteUntilClosed($graph, $result);

        $container->setParameter(self::PARAMETER_KEEP, $result->keepIds());
        $container->setParameter(self::PARAMETER_REPORT, $result->report());
        $container->setParameter(self::PARAMETER_UNMATCHED, $result->unmatched());
    }

    private function classify(ServiceGraph $graph, Classification $result): void
    {
        foreach ($graph->definitions() as $id => $definition) {
            if ($definition->isSynthetic() || $definition->isAbstract() || !$definition->isShared()) {
                continue;
            }
            $class = $graph->resolveClass($definition);
            ['stateless' => $stateless, 'reason' => $reason] = $this->analyzer->analyze($class);
            foreach ($this->keepIdPatterns as $pattern => $patternReason) {
                if (preg_match($pattern, $id) === 1) {
                    [$stateless, $reason] = [true, $patternReason];
                }
            }
            $inlined = $stateless ? $graph->mutableInlinedClass($definition) : null;
            if ($inlined !== null) {
                [$stateless, $reason] = [false, 'inlined-mutable:' . $inlined];
            }
            $result->add($id, $class, $stateless, $reason);
        }
    }

    private function protectCuratedPins(ServiceGraph $graph, Classification $result): void
    {
        $stack = $this->curated($graph, $result, $this->pinnedServices);
        while ($stack !== []) {
            $id = array_pop($stack);
            if ($result->protect($id)) {
                array_push($stack, ...$graph->dependencies($id));
            }
        }
    }

    private function applyDiscards(ServiceGraph $graph, Classification $result): void
    {
        foreach ($this->curated($graph, $result, $this->discardServices) as $id) {
            $result->discard($id, Classification::CURATED);
        }
        foreach ($this->tagged($graph, self::TAG_DISCARD) as $id) {
            $result->discard($id, Classification::TAG);
        }
        foreach ($this->configuration->discard as $name => $origin) {
            foreach ($this->configured($graph, $result, WorkerConfiguration::KEY_DISCARD, $name, $origin) as $id) {
                $result->discard($id, Classification::reason(Classification::CONFIG, $origin));
            }
        }
    }

    private function applyPatterns(Classification $result): void
    {
        $patterns = array_fill_keys($this->discardPatterns, Classification::PATTERN);
        foreach ($this->configuration->discardPatterns as $pattern => $origin) {
            $patterns[$pattern] = Classification::reason(Classification::PATTERN, Classification::CONFIG, $origin);
        }
        foreach ($result->ids() as $id) {
            if ($result->isExplicitlyDiscarded($id)) {
                continue;
            }
            foreach ($patterns as $pattern => $reason) {
                if (preg_match($pattern, $id) === 1 || preg_match($pattern, $result->classOf($id)) === 1) {
                    $result->discard($id, $reason);
                    break;
                }
            }
        }
    }

    private function applyKeeps(ServiceGraph $graph, Classification $result): void
    {
        foreach ($this->curated($graph, $result, $this->softServices) as $id) {
            $result->keep($id, Classification::SOFT);
        }
        foreach ($this->tagged($graph, self::TAG_KEEP, 'soft') as $id) {
            $result->keep($id, Classification::TAG);
        }
        foreach ($this->configuration->keep as $name => $origin) {
            foreach ($this->configured($graph, $result, WorkerConfiguration::KEY_KEEP, $name, $origin) as $id) {
                $result->keep($id, Classification::reason(Classification::CONFIG, $origin));
            }
        }
    }

    private function applyPins(ServiceGraph $graph, Classification $result): void
    {
        foreach ($this->curated($graph, $result, $this->pinnedServices) as $id) {
            $this->pin($graph, $result, $id, Classification::PINNED);
        }
        foreach ($this->tagged($graph, self::TAG_KEEP, 'pinned') as $id) {
            $this->pin($graph, $result, $id, Classification::PINNED);
        }
        foreach ($this->configuration->pinned as $name => $origin) {
            foreach ($this->configured($graph, $result, WorkerConfiguration::KEY_PIN, $name, $origin) as $id) {
                $this->pin($graph, $result, $id, Classification::reason(Classification::PINNED, Classification::CONFIG, $origin));
            }
        }
    }

    /**
     * Pins the root with its whole closure, or records the first blocker as a pin conflict.
     */
    private function pin(ServiceGraph $graph, Classification $result, string $root, string $reason): void
    {
        if (!$result->has($root) || $result->isExplicitlyDiscarded($root)) {
            return;
        }
        $closure = [];
        $stack = [[$root, [$root]]];
        while ($stack !== []) {
            [$id, $chain] = array_pop($stack);
            if (isset($closure[$id])) {
                continue;
            }
            $closure[$id] = true;
            foreach ($graph->dependencies($id) as $dependency) {
                $conflict = $graph->blocker($dependency)
                    ?? ($result->isExplicitlyDiscarded($dependency) ? 'explicit-discard' : null);
                if ($conflict !== null) {
                    $result->pinConflict($root, [...$chain, $dependency], $conflict);
                    return;
                }
                if ($result->has($dependency)) {
                    $stack[] = [$dependency, [...$chain, $dependency]];
                }
            }
        }
        $result->pin($root, array_keys($closure), $reason, $graph);
    }

    private function demoteUntilClosed(ServiceGraph $graph, Classification $result): void
    {
        do {
            $changed = false;
            foreach ($result->ids() as $id) {
                if (!$result->isKept($id) || $result->isPinned($id)) {
                    continue;
                }
                foreach ($graph->dependencies($id) as $dependency) {
                    $blocker = $graph->blocker($dependency) ?? ($result->isDiscarded($dependency) ? 'discard' : null);
                    if ($blocker === null) {
                        continue;
                    }
                    $result->demote($id, $dependency, $blocker);
                    $changed = true;
                    break;
                }
            }
        } while ($changed);
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function curated(ServiceGraph $graph, Classification $result, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            array_push($ids, ...$result->resolveNames($graph, $name));
        }
        return $ids;
    }

    /**
     * @return list<string>
     */
    private function tagged(ServiceGraph $graph, string $tag, ?string $mode = null): array
    {
        $ids = [];
        foreach ($graph->taggedIds($tag) as $id => $tags) {
            if ($mode === null || ($tags[0]['mode'] ?? 'soft') === $mode) {
                $ids[] = $graph->resolveId($id);
            }
        }
        return $ids;
    }

    /**
     * @return list<string>
     */
    private function configured(ServiceGraph $graph, Classification $result, string $list, string $name, string $origin): array
    {
        $ids = $result->resolveNames($graph, $name);
        if ($ids === []) {
            $result->noteUnmatched($list, $name, $origin);
        }
        return $ids;
    }
}
