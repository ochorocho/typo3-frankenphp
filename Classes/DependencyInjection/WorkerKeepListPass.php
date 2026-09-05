<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\DependencyInjection;

use Ochorocho\FrankenPhp\Worker\KeepList;
use Ochorocho\FrankenPhp\Worker\WorkerConfiguration;
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
 *  2. Overrides from KeepList, the `frankenphp.keep` / `frankenphp.discard`
 *     tags and the packages' Configuration/FrankenPhpWorker.php files. An
 *     explicit discard from any of these sources wins over a keep or pin.
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
    public const string PARAMETER_UNMATCHED = 'frankenphp.worker.unmatched';
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
     * Services registered under an id other than their class name.
     *
     * @var array<string, list<string>> class => service ids
     */
    private array $classIndex = [];

    /**
     * The dependency closure of the curated PINNED roots. Boot-populated
     * infrastructure: discarding any of it breaks the next request, so tag,
     * pattern and config discards are recorded here instead of applied.
     *
     * @var array<string, true>
     */
    private array $protected = [];

    /** @var array<string, string> protected id => the discard reason that was ignored */
    private array $ignoredDiscards = [];

    /**
     * Config entries that matched no shared service (typos, optional packages).
     *
     * @var list<array{list: string, name: string, origin: string}>
     */
    private array $unmatched = [];

    /**
     * Reason prefixes of explicit discard decisions, as opposed to the
     * intrinsic analysis. Only these block a keep request or a pin chain.
     *
     * @var list<string>
     */
    private const array EXPLICIT_DISCARD_REASONS = ['curated', 'tag', 'pattern', 'config'];

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
        private readonly WorkerConfiguration $configuration = new WorkerConfiguration(),
    ) {}

    public function process(ContainerBuilder $container): void
    {
        $this->report = [];
        $this->pinned = [];
        $this->classIndex = [];
        $this->protected = [];
        $this->ignoredDiscards = [];
        $this->unmatched = [];
        $graph = $container->getCompiler()->getServiceReferenceGraph();

        $this->classifyIntrinsically($container);
        $this->protectCuratedPins($container, $graph);
        $this->applyCuratedOverrides($container, $graph);
        $this->demoteUntilClosed($container, $graph);

        ksort($this->report);
        $keep = array_keys(array_filter(
            $this->report,
            static fn(array $entry): bool => $entry['category'] === self::CATEGORY_KEEP
        ));
        $container->setParameter(self::PARAMETER_KEEP, array_values($keep));
        $container->setParameter(self::PARAMETER_REPORT, $this->report);
        $container->setParameter(self::PARAMETER_UNMATCHED, $this->unmatched);
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
                $this->classIndex[$class][] = $id;
            }
        }
    }

    private function protectCuratedPins(ContainerBuilder $container, ServiceReferenceGraph $graph): void
    {
        $stack = [];
        foreach ($this->pinnedServices as $name) {
            array_push($stack, ...$this->resolveNames($container, $name));
        }
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($this->protected[$id]) || !isset($this->report[$id])) {
                continue;
            }
            $this->protected[$id] = true;
            array_push($stack, ...$this->dependencies($container, $graph, $id));
        }
    }

    private function applyCuratedOverrides(ContainerBuilder $container, ServiceReferenceGraph $graph): void
    {
        foreach ($this->discardServices as $name) {
            foreach ($this->resolveNames($container, $name) as $id) {
                $this->override($id, self::CATEGORY_DISCARD, 'curated');
            }
        }
        foreach ($container->findTaggedServiceIds(self::TAG_DISCARD) as $id => $_) {
            $this->override($this->resolveId($container, $id), self::CATEGORY_DISCARD, 'tag');
        }
        foreach ($this->configuration->discard as $name => $origin) {
            foreach ($this->resolveConfigured($container, WorkerConfiguration::KEY_DISCARD, $name, $origin) as $id) {
                $this->override($id, self::CATEGORY_DISCARD, 'config:' . $origin);
            }
        }

        $patterns = array_fill_keys($this->discardPatterns, 'pattern');
        foreach ($this->configuration->discardPatterns as $pattern => $origin) {
            $patterns[$pattern] = 'pattern:config:' . $origin;
        }
        foreach ($this->report as $id => $entry) {
            if ($this->isExplicitlyDiscarded($id)) {
                continue;
            }
            foreach ($patterns as $pattern => $reason) {
                $class = $entry['class'] ?? $id;
                if (preg_match($pattern, $id) === 1 || preg_match($pattern, $class) === 1) {
                    $this->override($id, self::CATEGORY_DISCARD, $reason);
                    break;
                }
            }
        }

        foreach ($this->softServices as $name) {
            foreach ($this->resolveNames($container, $name) as $id) {
                $this->override($id, self::CATEGORY_KEEP, 'soft');
            }
        }
        $tagPinned = [];
        foreach ($container->findTaggedServiceIds(self::TAG_KEEP) as $id => $tags) {
            if (($tags[0]['mode'] ?? 'soft') === 'pinned') {
                $tagPinned[] = $this->resolveId($container, $id);
            } else {
                $this->override($this->resolveId($container, $id), self::CATEGORY_KEEP, 'tag');
            }
        }
        foreach ($this->configuration->keep as $name => $origin) {
            foreach ($this->resolveConfigured($container, WorkerConfiguration::KEY_KEEP, $name, $origin) as $id) {
                $this->override($id, self::CATEGORY_KEEP, 'config:' . $origin);
            }
        }

        foreach ($this->pinnedServices as $name) {
            foreach ($this->resolveNames($container, $name) as $id) {
                $this->pin($container, $graph, $id, 'pinned');
            }
        }
        foreach ($tagPinned as $id) {
            $this->pin($container, $graph, $id, 'pinned');
        }
        foreach ($this->configuration->pinned as $name => $origin) {
            foreach ($this->resolveConfigured($container, WorkerConfiguration::KEY_PIN, $name, $origin) as $id) {
                $this->pin($container, $graph, $id, 'pinned:config:' . $origin);
            }
        }
    }

    private function override(string $id, string $category, string $reason): void
    {
        if (!isset($this->report[$id])) {
            return;
        }
        if ($category === self::CATEGORY_KEEP && $this->isExplicitlyDiscarded($id)) {
            return;
        }
        if ($category === self::CATEGORY_DISCARD && $reason !== 'curated' && isset($this->protected[$id])) {
            $this->ignoredDiscards[$id] = $reason;
            return;
        }
        $this->report[$id]['category'] = $category;
        $this->report[$id]['reason'] = $reason;
    }

    /**
     * Report ids a curated or configured name refers to: the id itself
     * (alias-resolved) plus every service registered under another id
     * whose class is that name.
     *
     * @return list<string>
     */
    private function resolveNames(ContainerBuilder $container, string $name): array
    {
        $ids = [];
        $id = $this->resolveId($container, $name);
        if (isset($this->report[$id])) {
            $ids[$id] = true;
        }
        foreach ($this->classIndex[ltrim($name, '\\')] ?? [] as $classId) {
            $ids[$classId] = true;
        }
        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function resolveConfigured(ContainerBuilder $container, string $list, string $name, string $origin): array
    {
        $ids = $this->resolveNames($container, $name);
        if ($ids === []) {
            $this->unmatched[] = ['list' => $list, 'name' => $name, 'origin' => $origin];
        }
        return $ids;
    }

    private function isExplicitlyDiscarded(string $id): bool
    {
        $entry = $this->report[$id] ?? null;
        return $entry !== null
            && $entry['category'] === self::CATEGORY_DISCARD
            && in_array(explode(':', $entry['reason'], 2)[0], self::EXPLICIT_DISCARD_REASONS, true);
    }

    /**
     * Pins a root and its whole dependency closure, or records a pin conflict.
     */
    private function pin(ContainerBuilder $container, ServiceReferenceGraph $graph, string $root, string $rootReason): void
    {
        if (!isset($this->report[$root]) || $this->isExplicitlyDiscarded($root)) {
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
            $reason = $id === $root ? $rootReason : 'pinned-via:' . $root;
            if (isset($this->ignoredDiscards[$id])) {
                $reason .= ':ignored-discard:' . $this->ignoredDiscards[$id];
            }
            $this->report[$id]['reason'] = $reason;
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
        if ($this->isExplicitlyDiscarded($dependency)) {
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
