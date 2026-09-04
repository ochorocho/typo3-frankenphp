<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\DependencyInjection;

use Ochorocho\FrankenPhp\Worker\KeepList;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceReferenceGraph;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Log\Logger;

/**
 * Classifies every shared service into "keep across worker requests" or
 * "discard per request" and stores the result as container parameters.
 *
 * Rules, in order:
 *  1. Intrinsic: readonly classes and classes whose instance properties are
 *     all readonly are kept; everything with mutable properties, opaque
 *     factory return types or unloadable classes is discarded.
 *  2. Curated overrides from KeepList and the `frankenphp.keep` /
 *     `frankenphp.discard` tags.
 *  3. Dependency closure: a kept service that (transitively, via
 *     constructor / inject-method / property references) holds a discarded
 *     service, a non-shared service or the per-request RequestId is demoted
 *     to discard, otherwise it would keep a stale instance while the
 *     container hands out a fresh one. PINNED entries invert the rule and
 *     pin their closure; a pinned chain hitting one of those blockers is a
 *     pin conflict and the root is discarded instead.
 *
 * Runs at TYPE_AFTER_REMOVING so the service reference graph is final
 * (post-inlining) and still available.
 */
final class WorkerKeepListPass implements CompilerPassInterface
{
    public const string PARAMETER_KEEP = 'frankenphp.worker.keep';
    public const string PARAMETER_REPORT = 'frankenphp.worker.report';
    public const string TAG_KEEP = 'frankenphp.keep';
    public const string TAG_DISCARD = 'frankenphp.discard';
    public const string REQUEST_ID_SERVICE = '_early.' . RequestId::class;

    public const string CATEGORY_KEEP = 'keep';
    public const string CATEGORY_DISCARD = 'discard';

    /**
     * Inlined helper objects that are safe to hold across requests: they
     * either resolve against the live container on every call or are
     * configured once at boot and never accumulate request data.
     *
     * @var list<class-string>
     */
    private const array SAFE_INLINE_CLASSES = [
        ServiceLocator::class,
        RewindableGenerator::class,
        Logger::class,
    ];

    /**
     * Kept small on purpose: it is dumped into the compiled container.
     * `class` is only present when it differs from the service id.
     *
     * @var array<string, array{category: string, reason: string, class?: string}>
     */
    private array $report = [];

    /** @var array<string, true> */
    private array $pinned = [];

    /**
     * @param list<string> $pinnedServices
     * @param list<string> $softServices
     * @param list<string> $discardServices
     * @param list<string> $discardPatterns
     * @param array<string, string> $keepIdPatterns
     */
    public function __construct(
        private readonly ClassStateAnalyzer $analyzer = new ClassStateAnalyzer(),
        private readonly array $pinnedServices = KeepList::PINNED,
        private readonly array $softServices = KeepList::SOFT,
        private readonly array $discardServices = KeepList::DISCARD,
        private readonly array $discardPatterns = KeepList::DISCARD_PATTERNS,
        private readonly array $keepIdPatterns = KeepList::KEEP_ID_PATTERNS,
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $this->report = [];
        $this->pinned = [];
        $graph = $container->getCompiler()->getServiceReferenceGraph();

        $this->classifyIntrinsically($container);
        $this->applyCuratedOverrides($container, $graph);
        $this->demoteUntilClosed($container, $graph);

        ksort($this->report);
        $keep = array_keys(array_filter(
            $this->report,
            static fn(array $entry): bool => $entry['category'] === self::CATEGORY_KEEP
        ));
        $container->setParameter(self::PARAMETER_KEEP, array_values($keep));
        $container->setParameter(self::PARAMETER_REPORT, $this->report);
    }

    private function classifyIntrinsically(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isSynthetic() || $definition->isAbstract() || !$definition->isShared()) {
                continue;
            }
            $class = $this->resolveClass($container, $definition);
            $analysis = $this->analyzer->analyze($class);
            $stateless = $analysis['stateless'];
            $reason = $analysis['reason'];

            foreach ($this->keepIdPatterns as $pattern => $patternReason) {
                if (preg_match($pattern, $id) === 1) {
                    $stateless = true;
                    $reason = $patternReason;
                }
            }
            if ($stateless) {
                $inlined = $this->findMutableInlinedDefinition($container, $definition);
                if ($inlined !== null) {
                    $stateless = false;
                    $reason = 'inlined-mutable:' . $inlined;
                }
            }

            $this->report[$id] = [
                'category' => $stateless ? self::CATEGORY_KEEP : self::CATEGORY_DISCARD,
                'reason' => $reason,
            ];
            if ($class !== null && $class !== $id) {
                $this->report[$id]['class'] = $class;
            }
        }
    }

    private function applyCuratedOverrides(ContainerBuilder $container, ServiceReferenceGraph $graph): void
    {
        foreach ($this->discardServices as $name) {
            $this->override($container, $name, self::CATEGORY_DISCARD, 'curated');
        }
        foreach ($container->findTaggedServiceIds(self::TAG_DISCARD) as $id => $_) {
            $this->override($container, $id, self::CATEGORY_DISCARD, 'tag');
        }
        foreach ($this->report as $id => $entry) {
            foreach ($this->discardPatterns as $pattern) {
                $class = $entry['class'] ?? $id;
                if (preg_match($pattern, $id) === 1 || preg_match($pattern, $class) === 1) {
                    $this->report[$id]['category'] = self::CATEGORY_DISCARD;
                    $this->report[$id]['reason'] = 'pattern';
                    break;
                }
            }
        }

        foreach ($this->softServices as $name) {
            $this->override($container, $name, self::CATEGORY_KEEP, 'soft');
        }
        $tagPinned = [];
        foreach ($container->findTaggedServiceIds(self::TAG_KEEP) as $id => $tags) {
            if (($tags[0]['mode'] ?? 'soft') === 'pinned') {
                $tagPinned[] = $id;
            } else {
                $this->override($container, $id, self::CATEGORY_KEEP, 'tag');
            }
        }

        foreach ([...$this->pinnedServices, ...$tagPinned] as $name) {
            $this->pin($container, $graph, $this->resolveId($container, $name));
        }
    }

    private function override(ContainerBuilder $container, string $name, string $category, string $reason): void
    {
        $id = $this->resolveId($container, $name);
        if (!isset($this->report[$id])) {
            return;
        }
        $this->report[$id]['category'] = $category;
        $this->report[$id]['reason'] = $reason;
    }

    /**
     * Pins a root and its whole dependency closure, or records a pin conflict.
     */
    private function pin(ContainerBuilder $container, ServiceReferenceGraph $graph, string $root): void
    {
        if (!isset($this->report[$root])) {
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
            foreach ($this->dependencies($container, $graph, $id) as $dependency) {
                $conflict = $this->pinConflict($container, $dependency);
                if ($conflict !== null) {
                    $this->report[$root]['category'] = self::CATEGORY_DISCARD;
                    $this->report[$root]['reason'] = sprintf(
                        'pin-conflict:%s (%s)',
                        implode(' -> ', [...$chain, $dependency]),
                        $conflict
                    );
                    return;
                }
                if (isset($this->report[$dependency]) && !isset($closure[$dependency])) {
                    $stack[] = [$dependency, [...$chain, $dependency]];
                }
            }
        }
        foreach (array_keys($closure) as $id) {
            if (isset($this->pinned[$id])) {
                continue;
            }
            $this->pinned[$id] = true;
            $this->report[$id]['category'] = self::CATEGORY_KEEP;
            $this->report[$id]['reason'] = $id === $root ? 'pinned' : 'pinned-via:' . $root;
            // Instances collected through iterators / locators are invisible
            // to the closure check; flag them so the audit reader looks twice.
            if ($this->holdsLazyCollection($container->getDefinition($id))) {
                $this->report[$id]['reason'] .= ' (holds lazily collected instances)';
            }
        }
    }

    private function pinConflict(ContainerBuilder $container, string $dependency): ?string
    {
        if ($dependency === self::REQUEST_ID_SERVICE) {
            return 'request-id';
        }
        if (!$container->hasDefinition($dependency)) {
            return null;
        }
        $definition = $container->getDefinition($dependency);
        if ($definition->isSynthetic()) {
            return null;
        }
        if (!$definition->isShared()) {
            return 'non-shared';
        }
        $entry = $this->report[$dependency] ?? null;
        if ($entry !== null
            && $entry['category'] === self::CATEGORY_DISCARD
            && in_array($entry['reason'], ['curated', 'tag', 'pattern'], true)
        ) {
            return 'explicit-discard';
        }
        return null;
    }

    private function demoteUntilClosed(ContainerBuilder $container, ServiceReferenceGraph $graph): void
    {
        do {
            $changed = false;
            foreach ($this->report as $id => $entry) {
                if ($entry['category'] !== self::CATEGORY_KEEP || isset($this->pinned[$id])) {
                    continue;
                }
                foreach ($this->dependencies($container, $graph, $id) as $dependency) {
                    $blocker = $this->blocker($container, $dependency);
                    if ($blocker === null) {
                        continue;
                    }
                    $this->report[$id]['category'] = self::CATEGORY_DISCARD;
                    $this->report[$id]['reason'] = sprintf('demoted-via:%s (%s)', $dependency, $blocker);
                    $changed = true;
                    break;
                }
            }
        } while ($changed);
    }

    private function blocker(ContainerBuilder $container, string $dependency): ?string
    {
        if ($dependency === self::REQUEST_ID_SERVICE) {
            return 'request-id';
        }
        if (!$container->hasDefinition($dependency)) {
            return null;
        }
        $definition = $container->getDefinition($dependency);
        if ($definition->isSynthetic()) {
            return null;
        }
        if (!$definition->isShared()) {
            return 'non-shared';
        }
        if (($this->report[$dependency]['category'] ?? null) === self::CATEGORY_DISCARD) {
            return 'discard';
        }
        return null;
    }

    /**
     * Captured (non-lazy, non-weak) references of a service, alias-resolved.
     *
     * @return list<string>
     */
    private function dependencies(ContainerBuilder $container, ServiceReferenceGraph $graph, string $id): array
    {
        if (!$graph->hasNode($id)) {
            return [];
        }
        $dependencies = [];
        foreach ($graph->getNode($id)->getOutEdges() as $edge) {
            if ($edge->isLazy() || $edge->isWeak()) {
                continue;
            }
            $destination = $this->resolveId($container, $edge->getDestNode()->getId());
            if ($destination === 'service_container' || $destination === $id) {
                continue;
            }
            $dependencies[$destination] = true;
        }
        return array_keys($dependencies);
    }

    private function resolveId(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = (string)$container->getAlias($id);
        }
        return $id;
    }

    private function resolveClass(ContainerBuilder $container, Definition $definition): ?string
    {
        $class = $definition->getClass();
        if ($class === null || $class === '') {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($class);
        return is_string($class) ? ltrim($class, '\\') : null;
    }

    /**
     * Inlined definitions become part of the holder's instance. A stateless
     * holder that owns a mutable inlined object is not stateless.
     */
    private function findMutableInlinedDefinition(ContainerBuilder $container, Definition $definition): ?string
    {
        $values = [$definition->getArguments(), $definition->getProperties()];
        foreach ($definition->getMethodCalls() as $call) {
            $values[] = $call[1] ?? [];
        }
        return $this->walkForMutableDefinition($container, $values);
    }

    private function walkForMutableDefinition(ContainerBuilder $container, mixed $value): ?string
    {
        if ($value instanceof ArgumentInterface) {
            // Iterator / locator arguments resolve lazily against the live container.
            return null;
        }
        if ($value instanceof Definition) {
            $class = $this->resolveClass($container, $value);
            if ($class !== null && !$this->isSafeInlineClass($class) && !$this->analyzer->analyze($class)['stateless']) {
                return $class;
            }
            return $this->findMutableInlinedDefinition($container, $value);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $found = $this->walkForMutableDefinition($container, $item);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function holdsLazyCollection(Definition $definition): bool
    {
        $values = [$definition->getArguments()];
        foreach ($definition->getMethodCalls() as $call) {
            $values[] = $call[1] ?? [];
        }
        array_walk_recursive($values, static function (mixed $value) use (&$found): void {
            if ($value instanceof ArgumentInterface) {
                $found = true;
            }
        });
        return $found ?? false;
    }

    private function isSafeInlineClass(string $class): bool
    {
        foreach (self::SAFE_INLINE_CLASSES as $safe) {
            if ($class === $safe || is_subclass_of($class, $safe)) {
                return true;
            }
        }
        return false;
    }
}
