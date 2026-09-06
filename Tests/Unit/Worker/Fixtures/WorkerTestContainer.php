<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker\Fixtures;

use Ochorocho\FrankenPhp\DependencyInjection\WorkerKeepListPass;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\Page\AssetCollector;

/**
 * The smallest container WorkerStateResetter accepts: the pinned services
 * it resets unconditionally, a runtime cache, the synthetic RequestId and
 * two plain services, one kept and one discarded.
 */
final class WorkerTestContainer
{
    public const string KEPT = 'test.kept';
    public const string DISCARDED = 'test.discarded';

    /**
     * @param array<string, object> $services additional services, discarded unless listed in $keep
     * @param list<string> $keep additional ids to keep
     */
    public static function create(array $services = [], array $keep = []): Container
    {
        $backend = new TransientMemoryBackend();
        $runtimeCache = new VariableFrontend('runtime', $backend);
        $backend->setCache($runtimeCache);

        $container = new Container();
        $container->set(Context::class, new Context());
        $container->set(AssetCollector::class, new AssetCollector());
        $container->set(MetaTagManagerRegistry::class, new MetaTagManagerRegistry());
        $container->set('cache.runtime', $runtimeCache);
        $container->set(WorkerKeepListPass::REQUEST_ID_SERVICE, new RequestId());
        $container->set(self::KEPT, new \stdClass());
        $container->set(self::DISCARDED, new \stdClass());
        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }
        // The compiled container protects RequestId as a synthetic service;
        // a plain Container has no synthetic ids, so keep it explicitly.
        $container->setParameter(WorkerKeepListPass::PARAMETER_KEEP, [
            WorkerKeepListPass::REQUEST_ID_SERVICE,
            Context::class,
            AssetCollector::class,
            MetaTagManagerRegistry::class,
            'cache.runtime',
            self::KEPT,
            ...$keep,
        ]);
        return $container;
    }
}
