<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

/**
 * Curated overrides for WorkerKeepListPass' stateless-by-analysis default.
 *
 *  - PINNED: boot-populated or process-wide, kept with their closure despite
 *    mutable properties. Never pin a registry holding instances collected
 *    from a tagged iterator (toolbar items, widgets, MFA providers): they
 *    carry the state of the request that built them first.
 *  - SOFT: kept only while their dependency closure stays clean.
 *  - DISCARD: never kept, even if a refactor makes them readonly.
 *  - RESEED: discarded, re-created after the wipe and fed the boot snapshot.
 */
final class KeepList
{
    /** @var list<string> */
    public const array PINNED = [
        // Process-wide infrastructure with reset hooks
        // (see WorkerStateResetter::resetKeptServices()).
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
        \TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory::class,
        \TYPO3\CMS\Core\Resource\Driver\DriverRegistry::class,
        \TYPO3\CMS\Core\Resource\Collection\FileCollectionRegistry::class,
        \TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry::class,
        \TYPO3\CMS\Core\Console\CommandRegistry::class,
        \TYPO3\CMS\Core\Site\Set\SetRegistry::class,
        \TYPO3\CMS\Core\Site\Set\SetCollector::class,
        \TYPO3\CMS\Core\Site\Set\YamlSetDefinitionProvider::class,
        \TYPO3\CMS\Core\Site\Set\CategoryRegistry::class,
        \TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry::class,
        \TYPO3\CMS\Backend\CodeEditor\Registry\AddonRegistry::class,
        \TYPO3\CMS\Backend\CodeEditor\Registry\ModeRegistry::class,
        \TYPO3\CMS\Extbase\Reflection\ReflectionService::class,
        \TYPO3\CMS\Extbase\Persistence\ClassesConfiguration::class,
        // Optional system extensions, referenced by name so the list does not
        // require them to be installed.
        'TYPO3\\CMS\\Webhooks\\WebhookTypesRegistry',
    ];

    /** @var list<string> */
    public const array SOFT = [];

    /** @var list<string> */
    public const array DISCARD = [
        // ModuleProvider::filterInaccessibleSubModules() removes submodules
        // from the shared module objects for restricted users. Kept, the
        // next admin on that worker inherits the reduced menu. Router routes
        // reference the same module objects, so it goes with it.
        \TYPO3\CMS\Backend\Module\ModuleRegistry::class,
        \TYPO3\CMS\Backend\Module\ModuleProvider::class,
        \TYPO3\CMS\Backend\Routing\Router::class,
        \TYPO3\CMS\Core\Resource\StorageRepository::class,
        \TYPO3\CMS\Core\Resource\ResourceFactory::class,
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

    /**
     * cache.runtime entries that may survive a request. Everything else is
     * flushed per request (which is what PHP-FPM's process death did).
     * Only list keys whose content depends on files that a deployment
     * changes anyway (labels come from cache.l10n / XLF files, table info
     * from the DB schema), never anything derived from records or site
     * configuration: those are edited through the backend at runtime and a
     * change made on one worker is invisible to the runtime cache of the
     * others. Measured on the sandbox frontend: keeping these two groups
     * recovers roughly a quarter of the cost of a full flush.
     *
     * @var list<string> regular expressions matched against the entry key
     */
    public const array RUNTIME_CACHE_KEEP_PATTERNS = [
        '/^labels_/',
        '/^generic_[0-9a-f]+-tableinfo-cache_/',
    ];

    /**
     * Database connections survive requests (see ConnectionRecycler) and are
     * closed after this many idle seconds, well below any server wait_timeout.
     */
    public const int CONNECTION_MAX_IDLE_SECONDS = 60;

    /** @var list<string> */
    public const array RESEED = [
        \TYPO3\CMS\Core\Page\PageRenderer::class,
    ];
}
