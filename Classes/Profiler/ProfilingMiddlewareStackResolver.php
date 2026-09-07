<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use TYPO3\CMS\Core\Cache\Event\CacheWarmupEvent;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;

/**
 * Decorates Core's MiddlewareStackResolver: every `identifier => class` of a
 * resolved stack becomes a TimedMiddleware, so MiddlewareDispatcher times
 * each middleware without a vendor patch. Keys and order are preserved.
 *
 * Symfony moves the decorated service's tags onto the decorator, including
 * the cache-warmup listener, hence warmupCaches() delegates.
 */
final readonly class ProfilingMiddlewareStackResolver
{
    public function __construct(
        #[AutowireDecorated]
        private MiddlewareStackResolver $inner,
        private RequestProfile $profile,
        private ContainerInterface $container,
    ) {}

    /**
     * @return \ArrayObject<string, TimedMiddleware>
     */
    public function resolve(string $stackName): \ArrayObject
    {
        $wrapped = [];
        foreach ($this->inner->resolve($stackName) as $identifier => $target) {
            $wrapped[$identifier] = new TimedMiddleware($this->profile, $this->container, (string)$identifier, (string)$target);
        }
        return new \ArrayObject($wrapped);
    }

    public function warmupCaches(CacheWarmupEvent $event): void
    {
        $this->inner->warmupCaches($event);
    }
}
