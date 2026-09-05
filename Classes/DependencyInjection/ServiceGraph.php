<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\DependencyInjection;

use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\Compiler\ServiceReferenceGraph;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Log\Logger;

/**
 * Read-only view of the container while it compiles: aliases, classes and
 * the captured references between services.
 */
final class ServiceGraph
{
    public const string REQUEST_ID_SERVICE = '_early.' . RequestId::class;

    /** Inlined helpers that resolve against the live container or never carry request data. */
    private const array SAFE_INLINE_CLASSES = [ServiceLocator::class, RewindableGenerator::class, Logger::class];

    public function __construct(
        private readonly ContainerBuilder $container,
        private readonly ServiceReferenceGraph $graph,
        private readonly ClassStateAnalyzer $analyzer,
    ) {}

    /**
     * @return array<string, Definition>
     */
    public function definitions(): array
    {
        return $this->container->getDefinitions();
    }

    public function definition(string $id): Definition
    {
        return $this->container->getDefinition($id);
    }

    /**
     * @return array<string, list<array<string, mixed>>> id => tag attributes
     */
    public function taggedIds(string $tag): array
    {
        return $this->container->findTaggedServiceIds($tag);
    }

    public function resolveId(string $id): string
    {
        $seen = [];
        while ($this->container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = (string)$this->container->getAlias($id);
        }
        return $id;
    }

    public function resolveClass(Definition $definition): ?string
    {
        $class = $definition->getClass();
        if ($class === null || $class === '') {
            return null;
        }
        $class = $this->container->getParameterBag()->resolveValue($class);
        return is_string($class) ? ltrim($class, '\\') : null;
    }

    /**
     * Captured (non-lazy, non-weak) references, alias-resolved.
     *
     * @return list<string>
     */
    public function dependencies(string $id): array
    {
        if (!$this->graph->hasNode($id)) {
            return [];
        }
        $dependencies = [];
        foreach ($this->graph->getNode($id)->getOutEdges() as $edge) {
            if ($edge->isLazy() || $edge->isWeak()) {
                continue;
            }
            $destination = $this->resolveId($edge->getDestNode()->getId());
            if ($destination === 'service_container' || $destination === $id) {
                continue;
            }
            $dependencies[$destination] = true;
        }
        return array_keys($dependencies);
    }

    /**
     * Why a dependency can never be held across requests, or null.
     */
    public function blocker(string $dependency): ?string
    {
        if ($dependency === self::REQUEST_ID_SERVICE) {
            return 'request-id';
        }
        if (!$this->container->hasDefinition($dependency)) {
            return null;
        }
        $definition = $this->container->getDefinition($dependency);
        if ($definition->isSynthetic() || $definition->isShared()) {
            return null;
        }
        return 'non-shared';
    }

    /**
     * Class of a mutable object inlined into the definition, or null.
     * An inlined object becomes part of the holder's instance.
     */
    public function mutableInlinedClass(Definition $definition): ?string
    {
        return $this->findMutable($this->values($definition, true));
    }

    public function holdsLazyCollection(Definition $definition): bool
    {
        $found = false;
        $values = $this->values($definition, false);
        array_walk_recursive($values, static function (mixed $value) use (&$found): void {
            $found = $found || $value instanceof ArgumentInterface;
        });
        return $found;
    }

    /**
     * @return list<mixed>
     */
    private function values(Definition $definition, bool $withProperties): array
    {
        $values = [$definition->getArguments()];
        if ($withProperties) {
            $values[] = $definition->getProperties();
        }
        foreach ($definition->getMethodCalls() as $call) {
            $values[] = $call[1] ?? [];
        }
        return $values;
    }

    private function findMutable(mixed $value): ?string
    {
        if ($value instanceof ArgumentInterface) {
            // Iterators and locators resolve lazily against the live container.
            return null;
        }
        if ($value instanceof Definition) {
            $class = $this->resolveClass($value);
            if ($class !== null && !$this->isSafeInline($class) && !$this->analyzer->analyze($class)['stateless']) {
                return $class;
            }
            return $this->mutableInlinedClass($value);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $found = $this->findMutable($item);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function isSafeInline(string $class): bool
    {
        foreach (self::SAFE_INLINE_CLASSES as $safe) {
            if ($class === $safe || is_subclass_of($class, $safe)) {
                return true;
            }
        }
        return false;
    }
}
