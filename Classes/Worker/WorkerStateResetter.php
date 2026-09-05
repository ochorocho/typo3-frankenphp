<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

use Ochorocho\FrankenPhp\DependencyInjection\WorkerKeepListPass;
use Ochorocho\FrankenPhp\Event\WorkerRequestStartingEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Container as SymfonyContainer;
use TYPO3\CMS\Backend\Resource\PublicUrlPrefixer as BackendPublicUrlPrefixer;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\Filter\FileNameFilter;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\Menu\MenuContentObjectFactory;
use TYPO3\CMS\Frontend\Resource\PublicUrlPrefixer as FrontendPublicUrlPrefixer;

/**
 * Brings the worker process back to its post-boot state before every request.
 *
 * Model: discard-by-default. Every DI service instance that WorkerKeepListPass
 * did not classify as safe to keep is dropped from the container, so the next
 * `get()` rebuilds it from the compiled container. Kept services are either
 * stateless or get a targeted reset here. Nothing outside the container is
 * assumed clean: globals, native session, GeneralUtility's singleton registry
 * and the per-request RequestId are handled explicitly.
 *
 * `capture()` runs once after Bootstrap::init(); `reset()` runs at the start
 * of every request, before the application handles it.
 */
final readonly class WorkerStateResetter
{
    public function __construct(
        private ConnectionRecycler $connectionRecycler = new ConnectionRecycler(),
    ) {}

    public function capture(ContainerInterface $container): WorkerStateSnapshot
    {
        // ext_localconf.php may register menu types, assets and meta tag
        // managers during boot. Those registrations must survive every
        // request, so they are captured once and replayed per request.
        $menuFactoryMapping = [];
        if ($container->has(MenuContentObjectFactory::class)) {
            $menuFactory = $container->get(MenuContentObjectFactory::class);
            $menuFactoryMapping = \Closure::bind(
                static fn(): array => $menuFactory->menuTypeToClassMapping,
                null,
                MenuContentObjectFactory::class,
            )();
        }

        return new WorkerStateSnapshot(
            pageRendererState: $container->get(PageRenderer::class)->getState(),
            assetCollectorState: $container->get(AssetCollector::class)->getState(),
            metaTagRegistryState: $container->get(MetaTagManagerRegistry::class)->getState(),
            menuTypeToClassMapping: $menuFactoryMapping,
        );
    }

    /**
     * @return int number of service instances discarded from the container
     */
    public function reset(WorkerStateSnapshot $snapshot, ContainerInterface $container): int
    {
        // EXEC_TIME still holds the previous request's start until resetGlobals().
        $idleSeconds = time() - (int)($GLOBALS['EXEC_TIME'] ?? time());
        $this->connectionRecycler->recycle($idleSeconds);

        $this->resetProcessState();
        $this->resetGlobals();
        GeneralUtility::flushInternalRuntimeCaches();
        // Non-DI singletons created through makeInstance(). Disjoint from the
        // container's instances; anything needed again is re-created lazily.
        GeneralUtility::resetSingletonInstances([]);
        // Process-wide static flag any file listing consults; a privileged
        // user toggling it must not leak into the next user's request.
        FileNameFilter::setShowHiddenFilesAndFolders(false);
        $this->resetStaticCoreState();

        $discarded = 0;
        if ($container instanceof SymfonyContainer) {
            $this->rotateRequestId($container);
            $discarded = $this->discardServices($container);
        }

        $this->resetKeptServices($container, $snapshot);
        $this->reseed($container, $snapshot);

        if ($container->has(EventDispatcherInterface::class)) {
            $container->get(EventDispatcherInterface::class)
                ->dispatch(new WorkerRequestStartingEvent($container));
        }
        return $discarded;
    }

    /**
     * RequestId is a synthetic `_early.*` service created once in
     * Bootstrap::init(). Its CSP nonce would otherwise be identical for every
     * response of the worker's lifetime. Symfony allows replacing synthetic
     * services at any time; every consumer holding the old one is discarded
     * per request by the keep-list closure rule.
     */
    private function rotateRequestId(SymfonyContainer $container): void
    {
        $requestId = new RequestId();
        $container->set(WorkerKeepListPass::REQUEST_ID_SERVICE, $requestId);

        // LogManager is synthetic too and stamps its request id on every
        // log record. Point it at the new id and drop its logger cache.
        if ($container->has(LogManager::class)) {
            $logManager = $container->get(LogManager::class);
            \Closure::bind(static function () use ($logManager, $requestId): void {
                $logManager->requestId = (string)$requestId;
            }, null, LogManager::class)();
            $logManager->reset();
        }
    }

    /**
     * Drops every instantiated service that is not in the keep set. Synthetic
     * services stay: they only exist because Bootstrap::init() injected them.
     * The one place this extension reaches into non-public state, and it is
     * Symfony's stable Container internals rather than TYPO3's.
     */
    private function discardServices(SymfonyContainer $container): int
    {
        if (!$container->hasParameter(WorkerKeepListPass::PARAMETER_KEEP)) {
            error_log('TYPO3 Worker: keep-list parameter missing, container instances are not discarded. Flush the DI cache.');
            return 0;
        }
        /** @var list<string> $keepIds */
        $keepIds = $container->getParameter(WorkerKeepListPass::PARAMETER_KEEP);
        $keep = array_fill_keys($keepIds, true);

        return \Closure::bind(function (array $keep): int {
            $discarded = 0;
            foreach ($this->services as $id => $_) {
                if (isset($keep[$id]) || isset($this->syntheticIds[$id]) || $id === 'service_container') {
                    continue;
                }
                unset($this->services[$id]);
                $discarded++;
            }
            foreach ($this->privates as $id => $_) {
                if (isset($keep[$id]) || $id === 'service_container') {
                    continue;
                }
                unset($this->privates[$id]);
                $discarded++;
            }
            return $discarded;
        }, $container, SymfonyContainer::class)($keep);
    }

    /**
     * Targeted resets for pinned services that carry per-request state but
     * cannot be discarded because they are boot-populated or held by most of
     * the container.
     */
    private function resetKeptServices(ContainerInterface $container, WorkerStateSnapshot $snapshot): void
    {
        // Context: middlewares repopulate the aspects they need; anything
        // left from the previous request (user, workspace, simulated date,
        // security nonce pool) must go. Aspects are lazily re-created with
        // defaults from the fresh EXEC_TIME.
        $context = $container->get(Context::class);
        $aspectNames = \Closure::bind(static fn(): array => array_keys($context->aspects), null, Context::class)();
        foreach ($aspectNames as $name) {
            $context->unsetAspect($name);
        }

        // cache.runtime's backend is documented as "stores cache entries during
        // one script run". Core caches user- and page-bound data in it under
        // fixed keys (file mounts, backend layouts, workspace lists, form
        // protection instances, page rows, rootlines). It is registered with
        // the CacheManager, so it cannot be discarded without creating a
        // second instance: flush it, keeping only the entries KeepList marks
        // as request-independent.
        if ($container->has('cache.runtime')) {
            $this->flushRuntimeCache($container->get('cache.runtime'));
        }

        // Boot-populated registries with a public state API: replay the
        // post-boot snapshot so ext_localconf.php registrations survive.
        $container->get(AssetCollector::class)->updateState($snapshot->assetCollectorState);
        $container->get(MetaTagManagerRegistry::class)->updateState($snapshot->metaTagRegistryState);

        if ($container->has(MenuContentObjectFactory::class)) {
            $menuFactory = $container->get(MenuContentObjectFactory::class);
            $bootMapping = $snapshot->menuTypeToClassMapping;
            \Closure::bind(static function () use ($menuFactory, $bootMapping): void {
                $menuFactory->menuTypeToClassMapping = $bootMapping;
            }, null, MenuContentObjectFactory::class)();
        }
    }

    /**
     * Selective flush: every entry goes unless its key matches one of
     * KeepList::RUNTIME_CACHE_KEEP_PATTERNS. Kept entries keep their tags.
     * TransientMemoryBackend exposes no key enumeration, hence the bind.
     */
    private function flushRuntimeCache(FrontendInterface $runtimeCache): void
    {
        $backend = $runtimeCache->getBackend();
        if (!$backend instanceof TransientMemoryBackend) {
            $runtimeCache->flush();
            return;
        }
        $patterns = KeepList::RUNTIME_CACHE_KEEP_PATTERNS;
        \Closure::bind(static function () use ($backend, $patterns): void {
            $removed = [];
            foreach ($backend->entries as $key => $_) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $key) === 1) {
                        continue 2;
                    }
                }
                unset($backend->entries[$key]);
                $removed[$key] = true;
            }
            foreach ($backend->tagsAndEntries as $tag => $identifiers) {
                $backend->tagsAndEntries[$tag] = array_diff_key($identifiers, $removed);
                if ($backend->tagsAndEntries[$tag] === []) {
                    unset($backend->tagsAndEntries[$tag]);
                }
            }
        }, null, TransientMemoryBackend::class)();
    }

    /**
     * PageRenderer is discarded (it depends on per-request services) but
     * ext_localconf.php may have added assets to it during boot. Eagerly
     * re-create it and replay the boot state; the nonce is excluded from the
     * state and set per request by Core.
     */
    private function reseed(ContainerInterface $container, WorkerStateSnapshot $snapshot): void
    {
        if ($container->has(PageRenderer::class)) {
            $container->get(PageRenderer::class)->updateState($snapshot->pageRendererState);
        }
    }

    /**
     * Static properties survive instance discarding. These are the Core
     * statics that carry per-request data and are only cleared on the
     * happy path; an exception mid-request leaves them dirty.
     */
    private function resetStaticCoreState(): void
    {
        // Cache-clear queue: committed by processClearCacheQueue() at the end
        // of a successful DataHandler run. Left over after an exception, the
        // next request's DataHandler flushes pages it never touched.
        \Closure::bind(static function (): void {
            DataHandler::$recordsToClearCacheFor = [];
            DataHandler::$recordPidsForDeletedRecords = [];
        }, null, DataHandler::class)();

        // Re-entrancy guard around public URL prefixing. Sticky `true` after
        // an exception silently disables prefixing for the worker's lifetime.
        foreach ([BackendPublicUrlPrefixer::class, FrontendPublicUrlPrefixer::class] as $prefixer) {
            if (!class_exists($prefixer)) {
                continue;
            }
            \Closure::bind(static function () use ($prefixer): void {
                $prefixer::$isProcessingUrl = false;
            }, null, $prefixer)();
        }
    }

    private function resetProcessState(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    private function resetGlobals(): void
    {
        // Set per request by Core middlewares; unset so a stale value cannot
        // leak into ServerRequestFactory::fromGlobals() or user lookups.
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG'], $GLOBALS['TYPO3_REQUEST']);

        // SystemEnvironmentBuilder::run() sets these once at boot. In worker
        // mode they freeze at the worker start time; refresh per request so
        // TYPO3's "now" matches reality.
        $now = time();
        $GLOBALS['EXEC_TIME'] = $now;
        $GLOBALS['ACCESS_TIME'] = $now - $now % 60;
        $GLOBALS['SIM_EXEC_TIME'] = $GLOBALS['EXEC_TIME'];
        $GLOBALS['SIM_ACCESS_TIME'] = $GLOBALS['ACCESS_TIME'];

        // $GLOBALS['T3_SERVICES'] is intentionally NOT reset: it is populated
        // once at boot from ext_localconf.php. Wiping it removes the auth
        // service registration and silently breaks login.
    }
}
