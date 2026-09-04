<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

/**
 * Curated overrides for the worker-mode service lifecycle.
 *
 * WorkerKeepListPass keeps every shared service that is provably stateless
 * (readonly class / readonly properties only) and discards the rest per
 * request. These lists tune that default:
 *
 *  - PINNED: boot-populated or process-wide services that must survive even
 *    though they have mutable properties. Their dependency closure is pinned
 *    as well. A pinned chain that reaches RequestId, a non-shared service or
 *    an explicit DISCARD is reported as a pin conflict and NOT kept.
 *  - SOFT: kept only if their dependency closure stays clean.
 *  - DISCARD: never kept, even if a future refactor makes them readonly.
 *  - RESEED: discarded, then eagerly re-created after the wipe and fed the
 *    post-boot state snapshot (services that ext_localconf.php may mutate).
 */
final class KeepList
{
    /** @var list<string> */
    public const array PINNED = [
        // Process-wide infrastructure with reset hooks (see KeepHooks).
        \TYPO3\CMS\Core\Context\Context::class,
        \TYPO3\CMS\Core\Cache\CacheManager::class,
        'cache.runtime',
        \TYPO3\CMS\Core\Database\ConnectionPool::class,
        \TYPO3\CMS\Core\EventDispatcher\EventDispatcher::class,
        \TYPO3\CMS\Core\EventDispatcher\ListenerProvider::class,
        \TYPO3\CMS\Core\Http\MiddlewareStackResolver::class,
        // Populated once during Bootstrap::init() / ext_localconf.php.
        \TYPO3\CMS\Core\Schema\TcaSchemaFactory::class,
        \TYPO3\CMS\Core\Imaging\IconRegistry::class,
        \TYPO3\CMS\Core\Resource\Rendering\RendererRegistry::class,
        \TYPO3\CMS\Core\Resource\TextExtraction\TextExtractorRegistry::class,
        \TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry::class,
        \TYPO3\CMS\Core\Page\AssetCollector::class,
        \TYPO3\CMS\Frontend\ContentObject\Menu\MenuContentObjectFactory::class,
        // Configuration registries, computed once from static config.
        \TYPO3\CMS\Core\Localization\Locales::class,
        \TYPO3\CMS\Core\Country\CountryProvider::class,
        \TYPO3\CMS\Backend\Routing\Router::class,
        \TYPO3\CMS\Backend\Module\ModuleRegistry::class,
        \TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory::class,
        \TYPO3\CMS\Core\Resource\Driver\DriverRegistry::class,
        \TYPO3\CMS\Core\Resource\Collection\FileCollectionRegistry::class,
        \TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry::class,
        \TYPO3\CMS\Core\LinkHandling\LinkService::class,
        \TYPO3\CMS\Core\Authentication\Mfa\MfaProviderRegistry::class,
        \TYPO3\CMS\Core\Console\CommandRegistry::class,
        \TYPO3\CMS\Core\Site\Set\SetRegistry::class,
        \TYPO3\CMS\Extbase\Reflection\ReflectionService::class,
    ];

    /** @var list<string> */
    public const array SOFT = [];

    /** @var list<string> */
    public const array DISCARD = [
        \TYPO3\CMS\Core\Resource\StorageRepository::class,
        \TYPO3\CMS\Core\Resource\ResourceFactory::class,
        \TYPO3\CMS\Core\Site\SiteFinder::class,
        \TYPO3\CMS\Backend\Routing\UriBuilder::class,
        \TYPO3\CMS\Core\Registry::class,
        \TYPO3\CMS\Core\Messaging\FlashMessageService::class,
        \TYPO3\CMS\Core\Session\SessionManager::class,
        \TYPO3\CMS\Extbase\Persistence\Generic\Session::class,
        \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager::class,
        \TYPO3\CMS\Extbase\Persistence\Generic\Backend::class,
        \TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class,
        \TYPO3\CMS\Extbase\Service\CacheService::class,
        \TYPO3\CMS\Extbase\Validation\ValidatorResolver::class,
        \TYPO3\CMS\Core\Mail\MemorySpool::class,
        \TYPO3\CMS\Core\Mail\Mailer::class,
        \TYPO3\CMS\Adminpanel\Log\InMemoryLogWriter::class,
        \TYPO3\CMS\Form\Slot\FilePersistenceSlot::class,
        \TYPO3\CMS\Form\Slot\ResourcePublicationSlot::class,
        \TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry::class,
        \TYPO3\CMS\Core\Security\ContentSecurityPolicy\DirectiveHashCollection::class,
        \TYPO3\CMS\Core\PageTitle\PageTitleProviderManager::class,
        \TYPO3\CMS\Core\TimeTracker\TimeTracker::class,
        \TYPO3\CMS\Backend\Template\Components\DocHeaderComponent::class,
        \TYPO3\CMS\Backend\Template\Components\ButtonBar::class,
        \TYPO3\CMS\Backend\Template\Components\MenuRegistry::class,
    ];

    /**
     * Regular expressions matched against the service id and class name.
     *
     * @var list<string>
     */
    public const array DISCARD_PATTERNS = [
        '/Controller$/',
        '/ToolbarItem$/',
    ];

    /**
     * Regular expressions matched against the service id; matches are kept
     * regardless of their class. Cache frontends are boot-stable objects the
     * CacheManager holds anyway, so discarding them would only create a
     * second instance next to the one the CacheManager already tracks.
     *
     * @var array<string, string> pattern => reason
     */
    public const array KEEP_ID_PATTERNS = [
        '/^cache\./' => 'cache-frontend',
    ];

    /** @var list<string> */
    public const array RESEED = [
        \TYPO3\CMS\Core\Page\PageRenderer::class,
    ];
}
