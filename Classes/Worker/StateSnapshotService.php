<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

use Ochorocho\FrankenPhp\Event\WorkerRequestStartingEvent;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Backend\ToolbarItems\SystemInformationToolbarItem;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\DocHeaderComponent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Captures TYPO3 singleton state immediately after Bootstrap::init() and
 * restores it at the start of every worker request.
 *
 * Background: DI-managed singletons (PageRenderer, AssetCollector,
 * MetaTagManagerRegistry, FlashMessageService, Context) accumulate state
 * across requests in worker mode. GeneralUtility::resetSingletonInstances()
 * only clears the makeInstance cache, not DI singletons.
 *
 * Most reset methods used here are added to TYPO3 Core via patches in
 * Patches/ (applied via cweagans/composer-patches). See Patches/PATCHES.md
 * for the full list.
 */
final class StateSnapshotService
{
    public function capture(ContainerInterface $container): WorkerStateSnapshot
    {
        $menuFactoryMapping = [];
        if ($container->has(\TYPO3\CMS\Frontend\ContentObject\Menu\MenuContentObjectFactory::class)) {
            $menuFactoryMapping = $container->get(\TYPO3\CMS\Frontend\ContentObject\Menu\MenuContentObjectFactory::class)
                ->getMenuTypeMapping();
        }

        return new WorkerStateSnapshot(
            pageRendererState: $container->get(PageRenderer::class)->getState(),
            assetCollectorState: $container->get(AssetCollector::class)->getState(),
            metaTagRegistryState: $container->get(MetaTagManagerRegistry::class)->getState(),
            menuTypeToClassMapping: $menuFactoryMapping,
        );
    }

    public function restore(WorkerStateSnapshot $snapshot, ContainerInterface $container): void
    {
        $this->resetProcessState();
        $this->resetGlobals();
        GeneralUtility::flushInternalRuntimeCaches();

        // Targeted cache.runtime invalidation for keys that lack per-user
        // or per-page parameterization. Flushing all of cache.runtime breaks
        // the login flow, so remove just the offending keys.
        //
        // Note: backendUserAuthenticationFileMountRecords and
        // workspace-service-available-workspaces are now user-scoped via
        // patches, but we still flush old un-scoped keys that may linger
        // from the boot request.
        if ($container->has('cache.runtime')) {
            $runtimeCache = $container->get('cache.runtime');
            foreach ([
                'ContentFetcher_fetchedContentRecords',
                'backend-layout-view-selected-backend-layouts',
                'backend-layout-view-selected-combined-identifiers',
                'backendUserAuthenticationFileMountRecords',
                'generalUtilityXml2Array',
                'formEngineUtilityTsConfigForTableRow',
                'workspace-service-available-workspaces',
                'workspace-service-available-workspaces-detailed',
            ] as $key) {
                $runtimeCache->remove($key);
            }
        }

        // Context aspects: middleware re-populates per request but stale
        // aspects from a crashed request must not leak.
        // The `security` aspect is deliberately NOT reset — see worker.php
        // comments for the RequestTokenMiddleware nonce-pool rationale.
        $context = $container->get(Context::class);
        $context->setAspect('backend.user', new UserAspect(null));
        $context->setAspect('frontend.user', new UserAspect(null));
        $context->setAspect('workspace', new WorkspaceAspect(0));

        // FormProtectionFactory: session-aware cache key is handled by the
        // cms-core patch — no per-request reset needed here.

        // UriBuilder caches generated URIs with embedded CSRF tokens. The
        // cache key is computed before token injection, so stale tokens
        // from a previous session persist. Clear per request.
        if ($container->has(UriBuilder::class)) {
            $container->get(UriBuilder::class)->resetGeneratedCache();
        }

        \TYPO3\CMS\Core\Resource\Filter\FileNameFilter::setShowHiddenFilesAndFolders(false);

        if ($container->has(\TYPO3\CMS\Core\Mail\MemorySpool::class)) {
            $container->get(\TYPO3\CMS\Core\Mail\MemorySpool::class)->reset();
        }

        if ($container->has(\TYPO3\CMS\Core\Registry::class)) {
            $container->get(\TYPO3\CMS\Core\Registry::class)->flushInMemoryCache();
        }

        // Extbase persistence: clearState() now cascades to Session::destroy()
        // and Backend::clearState() via the patched PersistenceManager.
        if ($container->has(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class)) {
            $container->get(\TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class)
                ->clearState();
        }

        if ($container->has(\TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class)) {
            $container->get(\TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class)
                ->clearCache();
        }

        if ($container->has(\TYPO3\CMS\Extbase\Service\CacheService::class)) {
            $container->get(\TYPO3\CMS\Extbase\Service\CacheService::class)
                ->clearState();
        }

        if ($container->has(\TYPO3\CMS\Extbase\Validation\ValidatorResolver::class)) {
            $container->get(\TYPO3\CMS\Extbase\Validation\ValidatorResolver::class)
                ->clearCache();
        }

        if ($container->has(\TYPO3\CMS\Frontend\ContentObject\Menu\MenuContentObjectFactory::class)) {
            $container->get(\TYPO3\CMS\Frontend\ContentObject\Menu\MenuContentObjectFactory::class)
                ->setMenuTypeMapping($snapshot->menuTypeToClassMapping);
        }

        if ($container->has(\TYPO3\CMS\Adminpanel\Log\InMemoryLogWriter::class)) {
            $container->get(\TYPO3\CMS\Adminpanel\Log\InMemoryLogWriter::class)
                ->clearLog();
        }

        if ($container->has(\TYPO3\CMS\Form\Slot\FilePersistenceSlot::class)) {
            $container->get(\TYPO3\CMS\Form\Slot\FilePersistenceSlot::class)
                ->clearAllowedInvocations();
        }

        if ($container->has(\TYPO3\CMS\Form\Slot\ResourcePublicationSlot::class)) {
            $container->get(\TYPO3\CMS\Form\Slot\ResourcePublicationSlot::class)
                ->clearFileIdentifiers();
        }

        if ($container->has(\TYPO3\CMS\Core\PageTitle\PageTitleProviderManager::class)) {
            $container->get(\TYPO3\CMS\Core\PageTitle\PageTitleProviderManager::class)
                ->setPageTitleCache([]);
        }

        // DI singletons with existing public state APIs.
        $container->get(PageRenderer::class)->updateState($snapshot->pageRendererState);
        $container->get(AssetCollector::class)->updateState($snapshot->assetCollectorState);
        $container->get(MetaTagManagerRegistry::class)->updateState($snapshot->metaTagRegistryState);

        $container->get(FlashMessageService::class)->resetQueues();

        // CSP PolicyRegistry already has a public setter; DirectiveHashCollection
        // gets reset() via patch.
        if ($container->has(\TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry::class)) {
            $container->get(\TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry::class)
                ->setMutationsCollections();
        }
        if ($container->has(\TYPO3\CMS\Core\Security\ContentSecurityPolicy\DirectiveHashCollection::class)) {
            $container->get(\TYPO3\CMS\Core\Security\ContentSecurityPolicy\DirectiveHashCollection::class)
                ->reset();
        }

        // DocHeaderComponent::resetState() resets its own ButtonBar + MenuRegistry,
        // but the DI container may hold a separate ButtonBar singleton that
        // controllers obtain directly — reset that too.
        if ($container->has(DocHeaderComponent::class)) {
            $container->get(DocHeaderComponent::class)->resetState();
        }
        if ($container->has(\TYPO3\CMS\Backend\Template\Components\ButtonBar::class)) {
            $container->get(\TYPO3\CMS\Backend\Template\Components\ButtonBar::class)
                ->resetState();
        }

        if ($container->has(SystemInformationToolbarItem::class)) {
            $container->get(SystemInformationToolbarItem::class)
                ->resetCollectedInformation();
        }

        $container->get(ConnectionPool::class)->resetConnections();

        if ($container->has(EventDispatcherInterface::class)) {
            $container->get(EventDispatcherInterface::class)
                ->dispatch(new WorkerRequestStartingEvent($container));
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
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG'], $GLOBALS['TYPO3_REQUEST']);

        $now = time();
        $GLOBALS['EXEC_TIME'] = $now;
        $GLOBALS['ACCESS_TIME'] = $now - $now % 60;
        $GLOBALS['SIM_EXEC_TIME'] = $GLOBALS['EXEC_TIME'];
        $GLOBALS['SIM_ACCESS_TIME'] = $GLOBALS['ACCESS_TIME'];
    }
}
