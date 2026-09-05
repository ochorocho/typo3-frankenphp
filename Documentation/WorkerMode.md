# Worker mode: service lifecycle

This document describes how `ochorocho/frankenphp` keeps TYPO3 correct inside a
long-running FrankenPHP worker, and lists which Core services survive a request.
Regenerate the table with:

```bash
vendor/bin/typo3 frankenphp:audit --format=markdown
```

## Model: discard by default

TYPO3's DI container is compiled once and its service *instances* are memoised
in Symfony's `Container::$services` / `$privates`. Under PHP-FPM those instances
die with the process; in a worker they survive and accumulate request state.

Instead of resetting known stateful services one by one (a blacklist that
drifts with every Core release), the extension classifies every shared service
at container compile time (`WorkerKeepListPass`) and, at the start of every
request (`WorkerStateResetter`), drops every instance that was not classified as
safe to keep. The compiled container rebuilds dropped services lazily.

### Classification

| Category | Rule |
| --- | --- |
| `keep / readonly` | `readonly class` |
| `keep / readonly-props` | every instance property is readonly |
| `keep / cache-frontend` | `cache.*` ids; the CacheManager owns these anyway |
| `keep / pinned` | curated boot-populated services (`KeepList::PINNED`), `frankenphp.keep` with `mode: pinned`, `pin` entries of a `FrankenPhpWorker.php` (`pinned:config:<origin>`), and their dependency closure |
| `keep / tag` / `config` | `frankenphp.keep` tag, `keep` entries of a `FrankenPhpWorker.php` (`config:<origin>`); soft, still subject to closure demotion |
| `discard / mutable` | at least one mutable instance property |
| `discard / opaque` | factory return type is an interface or unknown |
| `discard / inlined-mutable` | holds an inlined definition of a mutable class |
| `discard / curated` / `pattern` / `tag` / `config` | `KeepList::DISCARD`, `*Controller`, `*ToolbarItem`, `frankenphp.discard` tag, `discard` / `discardPatterns` entries of a `FrankenPhpWorker.php` (`config:<origin>`, `pattern:config:<origin>`) |
| `discard / demoted-via` | kept intrinsically, but (transitively) holds a discarded service, a non-shared service or `RequestId` |
| `discard / pin-conflict` | a pinned root whose closure reaches one of those blockers; reported, not kept |

The **dependency closure** rule is what makes the model sound: a kept service
that holds a reference to a discarded one would keep a stale instance while the
container hands out a fresh one. Pinned services invert the rule and pin their
closure instead.

### Per-request reset order

1. Native session closed, `$_SESSION` cleared, `BE_USER` / `LANG` / `TYPO3_REQUEST`
   globals unset, `EXEC_TIME` family refreshed, `GeneralUtility` runtime caches
   and non-DI singleton registry cleared, static Core leaks reset
   (`FileNameFilter`, `DataHandler` cache-clear queue, `PublicUrlPrefixer` guard).
2. A fresh `RequestId` replaces the synthetic `_early.*` service, so every
   response carries its own CSP nonce. `LogManager` is pointed at it.
3. Every container instance outside the keep set is discarded.
4. Pinned services with per-request state are reset: all `Context` aspects
   removed, `cache.runtime` flushed, boot state replayed into
   `AssetCollector` / `MetaTagManagerRegistry` / `MenuContentObjectFactory`.
   Database connections are kept (`ConnectionRecycler`, before step 1):
   reconnecting per request was the dominant cost under load. A connection is
   closed only when the previous request died inside a transaction or after
   `KeepList::CONNECTION_MAX_IDLE_SECONDS` of idle time. See
   `Performance.md` for the numbers.
5. `PageRenderer` is re-created and fed the post-boot state (assets added by
   `ext_localconf.php` survive).
6. `WorkerRequestStartingEvent` is dispatched for third-party resets.

### Controlling your own services

The automatic classification is the default; these three hooks tune it. Both
declarative forms are inert when this extension is not installed, so shipping
them costs nothing. Like `Services.yaml`, they are read once when the DI
container is compiled: run `typo3 cache:flush` after editing.

**1. Tag a service** — for a package's own definitions, in `Services.yaml`
(or `#[AutoconfigureTag('frankenphp.keep', ['mode' => 'pinned'])]` on the
class, TYPO3 autoconfigures by default):

```yaml
Vendor\Ext\Service\MyRegistry:
  tags:
    - { name: frankenphp.keep, mode: pinned }   # or mode: soft
Vendor\Ext\Service\PerRequestThing:
  tags:
    - { name: frankenphp.discard }
```

**2. `Configuration/FrankenPhpWorker.php`** — one file per package, plain
data. It also reaches services of *other* packages and supports patterns, so
an extension can discard a Core service it knows to misbehave with its own
code, and a project can override any extension author from
`config/system/frankenphp-worker.php` without forking. Every key is optional;
unknown keys, non-string entries or invalid patterns break the container
build with the file name in the message.

```php
<?php
// EXT:my_ext/Configuration/FrankenPhpWorker.php
return [
    // Survive across requests together with their dependency closure.
    // Never pin a registry that stores instances collected from a tagged
    // iterator (toolbar items, widgets, …): those carry the state of the
    // request that built them first.
    'pin' => [
        \Vendor\MyExt\Registry\FormatRegistry::class,
    ],
    // Survive only while their dependency closure stays clean.
    'keep' => [
        \Vendor\MyExt\Service\PriceCalculator::class,
    ],
    // Always discarded, also when the analysis would keep them.
    'discard' => [
        \Vendor\MyExt\Service\RequestScopedCollector::class,
        'my_ext.legacy.service.id',
    ],
    // Regular expressions, matched against service id and class name.
    'discardPatterns' => [
        '/^Vendor\\\\MyExt\\\\Controller\\\\/',
    ],
];
```

Entries are service ids or class names. Aliases are resolved, and a class
name also reaches services registered under a custom id with that class.
Names that match no shared service (a typo, or a package that is not
installed) are listed by `frankenphp:audit` as a warning.

Precedence is two rules. **An explicit discard from any source wins** —
curated list, tag, pattern or file — over a `keep` or `pin` from any other
source. **Except the curated `KeepList::PINNED` infrastructure and its
dependency closure** (`TcaSchemaFactory`, `IconRegistry`, `cache.runtime`,
`Context`, …): those are filled once at boot, discarding them breaks the next
request, so a file or pattern cannot discard them. The request is recorded
instead and the audit shows it as `pinned:ignored-discard:config:my_ext`.
`keep` is soft and can still be demoted by the dependency closure; a `pin`
whose closure reaches an explicit discard, a non-shared service or
`RequestId` is reported as a pin conflict and not kept. Files are applied in
package dependency order, the project file last, and the audit names the
origin: `config:my_ext`, `pinned:config:my_ext`, `pattern:config:project`.

**3. Reset a pinned service per request** — the files carry no closures.
Listen to `WorkerRequestStartingEvent` (dispatched after the discard and the
built-in resets, with the container) and clear the state there.

### Known limitations

- Kept services hold the `Logger` they received at boot; log lines from those
  loggers carry the boot request id.
- `displayErrors=-1` (devIPmask) is evaluated once at boot.
- `GeneralUtility::setSingletonInstance()` calls from `ext_localconf.php` do not
  survive the per-request purge; use the `frankenphp.keep` tag, a
  `Configuration/FrankenPhpWorker.php` or listen to `WorkerRequestStartingEvent`.
- Static properties cannot be reset by discarding instances. The audit reports
  them (`static: …`); Core ones that carry request data are reset explicitly.
- The first request after a worker boot or a MAX_REQUESTS recycle pays the
  boot cost (up to ~2 s on the backend). Size `MAX_REQUESTS` so recycles are
  rare relative to traffic, and warm the pool before measuring.
- Cross-user leaks (permissions, module menu, toolbar items) only surface when
  requests of different users interleave on the same worker. Single-user load
  tests cannot see them; run `backend-multiuser.js` and the Playwright
  `storage-permission-isolation` / `cross-user-menu-toolbar-isolation` specs.

## Performance

Measured on the dev sandbox (SQLite, 2 workers, TYPO3 14.3.4, PHP 8.5, Apple Silicon).
`ab -n 600 -c 4` against a cached-page-free frontend URL, 600 requests each:

| Variant | req/s | mean | p95 |
| --- | --- | --- | --- |
| discard-by-default, full `cache.runtime` flush | 227 | 17.6 ms | 22 ms |
| discard-by-default, **no** runtime cache flush (incorrect: serves stale rows) | 530 | 7.5 ms | 11 ms |
| discard-by-default, selective flush (labels + table info kept) — **shipped** | 286 | 14.0 ms | 16 ms |

The reset itself (`X-FrankenPHP-Reset-Us`) costs 0.3 ms per request; re-instantiating
the ~50-100 discarded services is not measurable. The cost is recomputing what
Core memoises in `cache.runtime`: page rows, rootlines, cache lifetimes, menus,
TSconfig. PHP-FPM recomputes all of it on every request too, so this is the
honest per-request price; the old blacklist implementation was faster only
because it kept those rows across requests and served stale data. If a site
needs the old numbers back, `KeepList::RUNTIME_CACHE_KEEP_PATTERNS` is the
knob, and every pattern added there is a conscious staleness trade-off.

k6 load scenarios (`Tests/load`, 2 minutes each):

| Scenario | old blacklist | discard-by-default (shipped) |
| --- | --- | --- |
| frontend-load, 20 VU, p95 | 35.7 ms | 44.4 ms (100 % checks) |
| backend-load, 5 VU, p95 | 27.9 ms (4.3 % checks failed: unpatched form-protection redirect loop) | 39.0 ms (100 % checks) |

### Load test results (2026-09-04, dev sandbox, 2 workers, MAX_REQUESTS=500)

All heavy scenarios from `Tests/load` against a freshly started worker pool,
`X-FrankenPHP-Discarded` sampled every 10 s alongside the FrankenPHP process RSS:

| Scenario | Result | Notes |
| --- | --- | --- |
| frontend-soak, 10 VU x 10 min | 5860 req, 0 failed, 100 % checks | p95 per minute 28-40 ms, no drift; two `max` outliers (371 / 645 ms) at recycle boundaries |
| backend-soak, 5 VU x 10 min | 13440 req, 0 failed, 100 % checks | p95 per minute 28-42 ms, no drift; minute 0 `max` 2.0 s = first request after boot |
| mixed-workload, 8 + 2 VU x 5 min | 0 failed, 100 % checks | frontend p95 30 ms, backend p95 28 ms |
| frontend-stress, ramp to 100 VU | 18628 req, 0 failed, 100 % checks | p95 11 ms; the 2-worker pool queues, it does not fail |
| frontend-spike, 200 VU burst | 18309 req, 0 failed, 100 % checks | p95 1.05 s during the burst, 49 ms in the cool-down |
| backend-recycle, 550 sequential GETs | 1653 req, 0 failed, 100 % checks | fresh worker pool; no request missed the backend shell at the recycle boundary in this run |
| backend-multiuser, 2 admin + 2 editor VU x 3 min | 7228 req, 0 failed, 100 % checks | role markers (module menu, clear-cache dropdown, storage root access) never crossed users |

Process memory: 100-130 MB at rest, 240-260 MB during stress, 340 MB peak
during the spike, back to ~130-140 MB afterwards. The rest value crept from
~100 MB to ~135 MB over 30 minutes of mixed load; the discard count stayed at
its post-boot value for every request type (58 backend AJAX, 72-80 pages), so
the growth is allocator retention across recycles, not retained services.
No worker errors; the only new log entries were Core's breadcrumb warnings
for non-existent page ids.

### What a recycle looks like

With `MAX_REQUESTS=500` a worker exits after 500 requests and FrankenPHP boots
a replacement. In the measurements above that costs one request per boundary
(a few hundred ms on the frontend, up to 2 s for the first backend request)
and 3-4 requests per boundary that miss the backend shell in
`backend-recycle.js`. Neither affects p95. Treat a `max` outlier without a
p95 change as a recycle, and sustained per-minute drift as a leak.

## Findings worth fixing in Core

Found while curating the keep-list; each one is invisible under PHP-FPM
because the process dies after every request.

- `StorageRepository` caches `ResourceStorage` objects, and
  `StoragePermissionsAspect` writes the *current user's* permissions onto them
  only when a storage is first created. A second user on the same process
  inherits the first user's file permissions.
- `ModuleProvider::filterInaccessibleSubModules()` calls `removeSubModule()` on
  the module objects owned by the shared `ModuleRegistry`. After a restricted
  user, every later user on that process sees the reduced module menu.
- `ToolbarItemsRegistry` holds toolbar item instances whose constructors
  compute per-user data (`ClearCacheToolbarItem::$cacheActions`); the first
  user's items are served to everyone.
- `RequestId` (and with it the CSP nonce) is created once in `Bootstrap::init()`
  and injected as a synthetic service; nothing in Core re-creates it per request.
- `cache.runtime` is documented as per-script but is never flushed by Core;
  `FormProtectionFactory` and `BackendUserAuthentication` cache session-bound
  data in it under session-independent keys.
- `DataHandler::$recordsToClearCacheFor` and `PublicUrlPrefixer::$isProcessingUrl`
  are static and only reset on the happy path.
- `SystemEnvironmentBuilder` sets `EXEC_TIME` & co. once per process.

## Audit of TYPO3 14.3 (sandbox: core extensions + dashboard, form, workspaces, adminpanel)

Summary (1019 shared services):

| group | count |
| --- | --- |
| keep / readonly | 231 |
| keep / readonly-props | 122 |
| keep / pinned | 41 |
| keep / cache-frontend | 5 |
| discard / mutable | 305 |
| discard / demoted-via | 148 |
| discard / pattern | 127 |
| discard / curated | 26 |
| discard / opaque | 7 |
| discard / inlined-mutable | 6 |
| discard / unloadable | 1 |

Full table:

| category | service id | reason | properties |
| --- | --- | --- | --- |
| discard | TYPO3\CMS\Adminpanel\Log\InMemoryLogWriter | curated | mutable: log,memoryLock |
| discard | TYPO3\CMS\Backend\Module\ModuleProvider | curated |  |
| discard | TYPO3\CMS\Backend\Module\ModuleRegistry | curated | mutable: modules,moduleAliases |
| discard | TYPO3\CMS\Backend\Routing\Router | curated | mutable: routeCollection |
| discard | TYPO3\CMS\Backend\Routing\UriBuilder | curated | mutable: generated,requestContext |
| discard | TYPO3\CMS\Backend\Template\Components\ButtonBar | curated | mutable: buttons |
| discard | TYPO3\CMS\Backend\Template\Components\DocHeaderComponent | curated | mutable: metaInformation,buttonBar,breadcrumb,breadcrumbContext,enabled,languageSelector,automaticShortcutButton,automaticReloadButton |
| discard | TYPO3\CMS\Core\Mail\Mailer | curated | mutable: mailSettings,sentMessage,mailerHeader,transport |
| discard | TYPO3\CMS\Core\Mail\MemorySpool | curated | mutable: queuedMessages,retries,logger,rate,lastSent,dispatcher |
| discard | TYPO3\CMS\Core\Messaging\FlashMessageService | curated | mutable: flashMessageQueues |
| discard | TYPO3\CMS\Core\PageTitle\PageTitleProviderManager | curated | mutable: pageTitleCache |
| discard | TYPO3\CMS\Core\Registry | curated | mutable: entries,loadedNamespaces |
| discard | TYPO3\CMS\Core\Resource\ResourceFactory | curated |  |
| discard | TYPO3\CMS\Core\Resource\StorageRepository | curated | mutable: storageRowCache,localDriverStorageCache,storageInstances |
| discard | TYPO3\CMS\Core\Security\ContentSecurityPolicy\DirectiveHashCollection_decorated_1 | curated | mutable: hashValues |
| discard | TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry | curated | mutable: mutationCollections |
| discard | TYPO3\CMS\Core\Session\SessionManager | curated | mutable: sessionBackends |
| discard | TYPO3\CMS\Core\TimeTracker\TimeTracker | curated | mutable: isEnabled,starttime,finishtime,LR,wrapError,wrapIcon,uniqueCounter,tsStack,tsStackLevel,tsStackLevelMax,tsStackLog,tsStackPointer,currentHashPointer |
| discard | TYPO3\CMS\Extbase\Configuration\ConfigurationManager | curated | mutable: request,configuration,feConfigCache |
| discard | TYPO3\CMS\Extbase\Persistence\Generic\Backend | curated | mutable: persistenceManager,aggregateRootObjects,deletedEntities,changedEntities,visitedDuringPersistence |
| discard | TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager | curated | mutable: newObjects,changedObjects,addedObjects,removedObjects,queryFactory,backend,persistenceSession |
| discard | TYPO3\CMS\Extbase\Persistence\Generic\Session | curated | mutable: reconstitutedEntities,objectMap,identifierMap |
| discard | TYPO3\CMS\Extbase\Service\CacheService | curated | mutable: clearCacheForTables,cacheTagStack |
| discard | TYPO3\CMS\Extbase\Validation\ValidatorResolver | curated | mutable: baseValidatorConjunctions |
| discard | TYPO3\CMS\Form\Slot\FilePersistenceSlot | curated | mutable: allowedInvocations |
| discard | TYPO3\CMS\Form\Slot\ResourcePublicationSlot | curated | mutable: fileIdentifiers |
| discard | TYPO3\CMS\Backend\LoginProvider\UsernamePasswordLoginProvider | demoted-via:TYPO3\CMS\Backend\Authentication\PasswordReset (discard) |  |
| discard | dashboard.widget.bookmarks | demoted-via:TYPO3\CMS\Backend\Backend\Bookmark\BookmarkService (discard) |  |
| discard | TYPO3\CMS\Recycler\Service\RecyclerService | demoted-via:TYPO3\CMS\Backend\History\RecordHistory (discard) |  |
| discard | TYPO3\CMS\Workspaces\Service\HistoryService | demoted-via:TYPO3\CMS\Backend\History\RecordHistory (discard) |  |
| discard | TYPO3\CMS\Backend\Backend\Bookmark\BookmarkService | demoted-via:TYPO3\CMS\Backend\Module\ModuleProvider (discard) |  |
| discard | TYPO3\CMS\Backend\Backend\Bookmark\Security\BookmarkVoter | demoted-via:TYPO3\CMS\Backend\Module\ModuleProvider (discard) |  |
| discard | TYPO3\CMS\Backend\Module\ModuleResolver | demoted-via:TYPO3\CMS\Backend\Module\ModuleProvider (discard) |  |
| discard | TYPO3\CMS\Beuser\Service\UserInformationService | demoted-via:TYPO3\CMS\Backend\Module\ModuleProvider (discard) |  |
| discard | TYPO3\CMS\Core\Hooks\TcaItemsProcessorFunctions | demoted-via:TYPO3\CMS\Backend\Module\ModuleProvider (discard) |  |
| discard | TYPO3\CMS\Backend\Middleware\BackendRouteInitialization | demoted-via:TYPO3\CMS\Backend\Routing\Router (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Middleware\AdminPanelRenderer | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Breadcrumb\RecordBreadcrumbProvider | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Breadcrumb\ResourceBreadcrumbProvider | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\ReturnUrl | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Localization\ManualLocalizationHandler | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Middleware\BackendModuleValidator | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Middleware\JavaScriptLabelImportMapEntryResolver | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Belog\EventListener\SystemInformationEventListener | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Form\EventListener\ModifyFormDefinitionRecordListRowEventListener | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Redirects\EventListener\QrCodeNewDocHeaderButton | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Redirects\EventListener\ShortUrlNewDocHeaderButtonEventListener | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\SysNote\Provider\ButtonBarProvider | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Workspaces\Backend\LiveSearch\WorkspaceProvider | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | frankenphp.widget.prometheusMetrics | demoted-via:TYPO3\CMS\Backend\Routing\UriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Hooks\DataHandlerAuthenticationContext | demoted-via:TYPO3\CMS\Backend\Security\SudoMode\Access\AccessStorage (discard) |  |
| discard | TYPO3\CMS\Backend\Template\Components\Buttons\LanguageSelectorBuilder | demoted-via:TYPO3\CMS\Backend\Template\Components\ComponentFactory (discard) |  |
| discard | TYPO3\CMS\Form\EventListener\ModifyFormDefinitionRecordActionsEventListener | demoted-via:TYPO3\CMS\Backend\Template\Components\ComponentFactory (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaTtContentColPosItemsRestrictionByBackendLayout | demoted-via:TYPO3\CMS\Backend\View\BackendLayoutView (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaTtContentCtypeItemsRestrictionByBackendLayout | demoted-via:TYPO3\CMS\Backend\View\BackendLayoutView (discard) |  |
| discard | TYPO3\CMS\Backend\Hooks\DataHandlerContentElementRestrictionHook | demoted-via:TYPO3\CMS\Backend\View\BackendLayoutView (discard) |  |
| discard | TYPO3\CMS\Backend\View\BackendLayoutView | demoted-via:TYPO3\CMS\Backend\View\BackendLayout\DataProviderCollection (discard) |  |
| discard | TYPO3\CMS\Core\Page\PageLayoutResolver | demoted-via:TYPO3\CMS\Backend\View\BackendLayout\DataProviderCollection (discard) |  |
| discard | TYPO3\CMS\Core\Authentication\Mfa\Provider\RecoveryCodesProvider | demoted-via:TYPO3\CMS\Core\Authentication\Mfa\MfaProviderRegistry (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\SiteDatabaseEditRow | demoted-via:TYPO3\CMS\Core\Configuration\Processor\Placeholder\EnvPlaceholderProcessor (discard) |  |
| discard | TYPO3\CMS\Core\Schema\SchemaLabelResolver | demoted-via:TYPO3\CMS\Core\DataHandling\ItemProcessingService (discard) |  |
| discard | TYPO3\CMS\Core\Upgrades\NullToDefaultUpdateWizard | demoted-via:TYPO3\CMS\Core\Database\Schema\SchemaMigrator (discard) |  |
| discard | TYPO3\CMS\Core\Package\PackageSetup | demoted-via:TYPO3\CMS\Core\Database\Schema\SqlReader (discard) |  |
| discard | TYPO3\CMS\Backend\Breadcrumb\BreadcrumbFactory | demoted-via:TYPO3\CMS\Core\Domain\RecordFactory (discard) |  |
| discard | TYPO3\CMS\Backend\Domain\Repository\Localization\LocalizationRepository | demoted-via:TYPO3\CMS\Core\Domain\RecordFactory (discard) |  |
| discard | TYPO3\CMS\Core\Schema\VisibleSchemaFieldsCollector | demoted-via:TYPO3\CMS\Core\Domain\RecordFactory (discard) |  |
| discard | TYPO3\CMS\Frontend\Content\RecordCollector | demoted-via:TYPO3\CMS\Core\Domain\RecordFactory (discard) |  |
| discard | TYPO3\CMS\Frontend\DataProcessing\RecordTransformationProcessor | demoted-via:TYPO3\CMS\Core\Domain\RecordFactory (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaText | demoted-via:TYPO3\CMS\Core\Html\RteHtmlParser (discard) |  |
| discard | TYPO3\CMS\Form\Service\RichTextConfigurationService | demoted-via:TYPO3\CMS\Core\Html\RteHtmlParser (discard) |  |
| discard | TYPO3\CMS\Core\LinkHandling\PageTypeLinkResolver | demoted-via:TYPO3\CMS\Core\LinkHandling\LinkService (discard) |  |
| discard | TYPO3\CMS\Frontend\Middleware\StaticRouteResolver | demoted-via:TYPO3\CMS\Core\LinkHandling\LinkService (discard) |  |
| discard | TYPO3\CMS\Frontend\Typolink\LinkFactory | demoted-via:TYPO3\CMS\Core\LinkHandling\LinkService (discard) |  |
| discard | TYPO3\CMS\Backend\Authentication\PasswordReset | demoted-via:TYPO3\CMS\Core\Mail\Mailer (discard) |  |
| discard | TYPO3\CMS\Backend\EventListener\FailedMfaAttemptNotification | demoted-via:TYPO3\CMS\Core\Mail\Mailer (discard) |  |
| discard | TYPO3\CMS\Workspaces\MessageHandler\StageChangeNotificationHandler | demoted-via:TYPO3\CMS\Core\Mail\Mailer (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseSystemLanguageRows | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\SystemMaintainerAsReadonly | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Backend\View\BackendLayout\ContentFetcher | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Core\DataHandling\ItemProcessingService | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Core\Resource\SynchronizeFolderRelations | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Core\Site\SiteSettingsService | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Core\Site\TcaSiteSetCollector | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Form\EventListener\DataStructureIdentifierListener | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Redirects\Hooks\HandleNewShortUrlRecord | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Workspaces\Hook\BackendUtilityHook | demoted-via:TYPO3\CMS\Core\Messaging\FlashMessageService (discard) |  |
| discard | TYPO3\CMS\Core\Page\ImportMapCacheWarmer | demoted-via:TYPO3\CMS\Core\Page\ImportMapFactory (discard) |  |
| discard | TYPO3\CMS\Backend\EventListener\AfterBackendPageRenderEventListener | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormResultHandler | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Backend\Template\Components\ComponentFactory | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Dashboard\EventListener\AfterBackendPageRenderEventListener | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Filelist\EventListener\AfterBackendPageRenderEventListener | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Opendocs\EventListener\AfterBackendPageRenderEventListener | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Redirects\EventListener\AfterBackendPageRendererEventListener | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Scheduler\EventListener\ReplaceAddNewButtonToFormEngine | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Seo\Canonical\CanonicalGenerator | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Workspaces\EventListener\AfterBackendPageRenderEventListener | demoted-via:TYPO3\CMS\Core\Page\PageRenderer (discard) |  |
| discard | TYPO3\CMS\Core\Package\Initialization\ImportExtensionDataOnPackageInitialization | demoted-via:TYPO3\CMS\Core\Registry (discard) |  |
| discard | TYPO3\CMS\Core\Package\Initialization\ImportStaticSqlDataOnPackageInitialization | demoted-via:TYPO3\CMS\Core\Registry (discard) |  |
| discard | TYPO3\CMS\Impexp\Initialization\ImportContentOnPackageInitialization | demoted-via:TYPO3\CMS\Core\Registry (discard) |  |
| discard | TYPO3\CMS\Impexp\Initialization\ImportSiteConfigurationsOnPackageInitialization | demoted-via:TYPO3\CMS\Core\Registry (discard) |  |
| discard | TYPO3\CMS\Backend\View\BackendLayout\DefaultDataProvider | demoted-via:TYPO3\CMS\Core\Resource\FileRepository (discard) |  |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaFolder | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Core\Domain\RecordFactory | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Core\Hooks\UpdateFileIndexEntry | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Core\Resource\FileRepository | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Core\Resource\Security\FileMetadataPermissionsAspect | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Core\Resource\Security\FilePermissionAspect | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Extbase\Service\FileHandlingService | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Workspaces\Service\WorkspaceService | demoted-via:TYPO3\CMS\Core\Resource\ResourceFactory (discard) |  |
| discard | TYPO3\CMS\Backend\Breadcrumb\NullContextBreadcrumbProvider | demoted-via:TYPO3\CMS\Core\Resource\StorageRepository (discard) |  |
| discard | TYPO3\CMS\Core\SystemResource\SystemResourceFactory_decorated_1 | demoted-via:TYPO3\CMS\Core\Resource\StorageRepository (discard) |  |
| discard | TYPO3\CMS\Install\Service\Typo3tempFileService | demoted-via:TYPO3\CMS\Core\Resource\StorageRepository (discard) |  |
| discard | TYPO3\CMS\Backend\Middleware\ForcedHttpsBackendRedirector | demoted-via:TYPO3\CMS\Core\Routing\BackendEntryPointResolver (discard) |  |
| discard | TYPO3\CMS\Core\Http\RequestHandler | demoted-via:TYPO3\CMS\Core\Routing\BackendEntryPointResolver (discard) |  |
| discard | TYPO3\CMS\Backend\Middleware\ContentSecurityPolicyReporter | demoted-via:TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyProvider (discard) |  |
| discard | TYPO3\CMS\Frontend\Middleware\ContentSecurityPolicyReporter | demoted-via:TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyProvider (discard) |  |
| discard | TYPO3\CMS\Core\Page\ImportMapFactory | demoted-via:TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry (discard) |  |
| discard | TYPO3\CMS\Frontend\Cache\MetaDataState | demoted-via:TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyRegistry (discard) |  |
| discard | TYPO3\CMS\Backend\View\AuthenticationStyleInformation | demoted-via:TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Core\Package\Initialization\PublishAssetsOnPackageInitialization | demoted-via:TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Core\Page\AssetRenderer | demoted-via:TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Core\Page\ResourceHashCollection | demoted-via:TYPO3\CMS\Core\SystemResource\SystemResourceFactory_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Modules\Info\GeneralInformation | demoted-via:TYPO3\CMS\Core\TimeTracker\TimeTracker (discard) |  |
| discard | TYPO3\CMS\Frontend\Http\RequestHandler | demoted-via:TYPO3\CMS\Core\TimeTracker\TimeTracker (discard) |  |
| discard | TYPO3\CMS\Frontend\Middleware\PrepareTypoScriptFrontendRendering | demoted-via:TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory (discard) |  |
| discard | TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory | demoted-via:TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateTreeBuilder (discard) |  |
| discard | TYPO3\CMS\Core\TypoScript\IncludeTree\StringTreeBuilder | demoted-via:TYPO3\CMS\Core\TypoScript\IncludeTree\TreeFromLineStreamBuilder (discard) |  |
| discard | TYPO3\CMS\Core\TypoScript\IncludeTree\TsConfigTreeBuilder | demoted-via:TYPO3\CMS\Core\TypoScript\IncludeTree\TreeFromLineStreamBuilder (discard) |  |
| discard | TYPO3\CMS\Core\TypoScript\PageTsConfigFactory | demoted-via:TYPO3\CMS\Core\TypoScript\Tokenizer\LossyTokenizer (discard) |  |
| discard | TYPO3\CMS\Core\TypoScript\UserTsConfigFactory | demoted-via:TYPO3\CMS\Core\TypoScript\Tokenizer\LossyTokenizer (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Modules\Debug\Events | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Modules\Debug\PageTitle | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Modules\Debug\QueryInformation | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Modules\Info\PhpInformation | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Modules\Info\RequestInformation | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Adminpanel\Modules\Info\UserIntInformation | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Backend\Preview\FluidBasedContentPreviewRenderer | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Core\Authentication\Mfa\Provider\TotpProvider | demoted-via:TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 (discard) |  |
| discard | TYPO3\CMS\Extbase\Mvc\Web\RequestBuilder | demoted-via:TYPO3\CMS\Extbase\Configuration\ConfigurationManager (discard) |  |
| discard | TYPO3\CMS\Extbase\Persistence\Generic\QueryFactory | demoted-via:TYPO3\CMS\Extbase\Configuration\ConfigurationManager (discard) |  |
| discard | TYPO3\CMS\Extbase\Persistence\Generic\Storage\Typo3DbBackend | demoted-via:TYPO3\CMS\Extbase\Service\CacheService (discard) |  |
| discard | TYPO3\CMS\Form\Domain\Configuration\FormDefinitionValidationService | demoted-via:TYPO3\CMS\Form\Domain\Configuration\ConfigurationService (discard) |  |
| discard | TYPO3\CMS\Form\Mvc\Property\TypeConverter\FormDefinitionArrayConverter | demoted-via:TYPO3\CMS\Form\Domain\Configuration\FormDefinitionValidationService (discard) |  |
| discard | TYPO3\CMS\Form\Storage\DatabaseStorageAdapter | demoted-via:TYPO3\CMS\Form\Domain\Repository\FormDefinitionRepository (discard) |  |
| discard | TYPO3\CMS\Form\Upgrades\FileFormsToDatabaseUpgradeWizard | demoted-via:TYPO3\CMS\Form\Domain\Repository\FormDefinitionRepository (discard) |  |
| discard | TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface | demoted-via:TYPO3\CMS\Form\Mvc\Configuration\YamlSource (discard) |  |
| discard | TYPO3\CMS\Form\Domain\Configuration\FormDefinitionConversionService | demoted-via:TYPO3\CMS\Form\Service\RichTextConfigurationService (discard) |  |
| discard | TYPO3\CMS\Form\Service\FormEditorEnrichmentService | demoted-via:TYPO3\CMS\Form\Service\RichTextConfigurationService (discard) |  |
| discard | TYPO3\CMS\Form\Mvc\Configuration\YamlSource | demoted-via:TYPO3\CMS\Form\Slot\FilePersistenceSlot (discard) |  |
| discard | TYPO3\CMS\Form\Domain\Repository\FormDefinitionRepository | demoted-via:TYPO3\CMS\Form\Storage\Security\FormDefinitionPersistenceGuard (discard) |  |
| discard | TYPO3\CMS\Form\Hooks\FormDefinitionDataHandlerHook | demoted-via:TYPO3\CMS\Form\Storage\Security\FormDefinitionPersistenceGuard (discard) |  |
| discard | TYPO3\CMS\Frontend\Content\ContentAreaResolver | demoted-via:TYPO3\CMS\Frontend\Content\RecordCollector (discard) |  |
| discard | TYPO3\CMS\Frontend\DataProcessing\PageContentFetchingProcessor | demoted-via:TYPO3\CMS\Frontend\Content\RecordCollector (discard) |  |
| discard | TYPO3\CMS\Frontend\Middleware\SiteResolver | demoted-via:TYPO3\CMS\Frontend\Controller\ErrorController (discard) |  |
| discard | TYPO3\CMS\Frontend\Page\PageInformationFactory | demoted-via:TYPO3\CMS\Frontend\Controller\ErrorController (discard) |  |
| discard | TYPO3\CMS\Seo\XmlSitemap\XmlSitemapRenderer | demoted-via:TYPO3\CMS\Frontend\Controller\ErrorController (discard) |  |
| discard | TYPO3\CMS\Frontend\Middleware\PageArgumentValidator | demoted-via:TYPO3\CMS\Frontend\Page\CacheHashCalculator (discard) |  |
| discard | TYPO3\CMS\Redirects\Service\ModulePaginationService | demoted-via:TYPO3\CMS\Redirects\Repository\RedirectRepository (discard) |  |
| discard | TYPO3\CMS\Redirects\EventListener\RedirectEditPermissionGuard | demoted-via:TYPO3\CMS\Redirects\Security\RedirectPermissionGuard (discard) |  |
| discard | TYPO3\CMS\Redirects\Hooks\DataHandlerPermissionGuardHook | demoted-via:TYPO3\CMS\Redirects\Security\RedirectPermissionGuard (discard) |  |
| discard | TYPO3\CMS\Redirects\Service\RedirectService | demoted-via:TYPO3\CMS\Redirects\Service\RedirectCacheService (discard) |  |
| discard | TYPO3\CMS\Scheduler\Hooks\SchedulerTaskPersistenceValidator | demoted-via:TYPO3\CMS\Scheduler\Controller\SchedulerModuleController (discard) |  |
| discard | TYPO3\CMS\SysNote\Provider\InfoModuleProvider | demoted-via:TYPO3\CMS\SysNote\Renderer\NoteRenderer (discard) |  |
| discard | TYPO3\CMS\SysNote\Provider\PageModuleProvider | demoted-via:TYPO3\CMS\SysNote\Renderer\NoteRenderer (discard) |  |
| discard | TYPO3\CMS\SysNote\Provider\RecordListProvider | demoted-via:TYPO3\CMS\SysNote\Renderer\NoteRenderer (discard) |  |
| discard | TYPO3\CMS\Workspaces\EventListener\ModifyPreviewUrlForQrCodeListener | demoted-via:TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder (discard) |  |
| discard | TYPO3\CMS\Backend\Middleware\ContentSecurityPolicyHeaders | demoted-via:_early.TYPO3\CMS\Core\Core\RequestId (request-id) |  |
| discard | TYPO3\CMS\Core\Security\ContentSecurityPolicy\PolicyProvider | demoted-via:_early.TYPO3\CMS\Core\Core\RequestId (request-id) |  |
| discard | TYPO3\CMS\Frontend\Middleware\ContentSecurityPolicyHeaders | demoted-via:_early.TYPO3\CMS\Core\Core\RequestId (request-id) |  |
| discard | TYPO3\CMS\Webhooks\Listener\MessageListener | demoted-via:messenger.bus.default (discard) |  |
| discard | TYPO3\CMS\Webhooks\Listener\PageModificationListener | demoted-via:messenger.bus.default (discard) |  |
| discard | TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverDelegateRegistry | inlined-mutable:TYPO3\CMS\Core\Cache\Frontend\FrontendInterface |  |
| discard | TYPO3\CMS\Core\Database\Schema\SchemaMigrator | inlined-mutable:TYPO3\CMS\Core\Database\Schema\Parser\Parser |  |
| discard | TYPO3\CMS\Frontend\Middleware\EidHandler | inlined-mutable:TYPO3\CMS\Core\Http\Dispatcher |  |
| discard | TYPO3\CMS\Backend\UserFunctions\UserSettingsItemsProcFunc | inlined-mutable:TYPO3\CMS\Core\Localization\OfficialLanguages |  |
| discard | TYPO3\CMS\Core\Resource\Service\FileProcessingService | inlined-mutable:TYPO3\CMS\Core\Resource\Processing\ProcessorRegistry |  |
| discard | TYPO3\CMS\IndexedSearch\EventListener\FrontendGenerationPageIndexingTrigger | inlined-mutable:TYPO3\CMS\IndexedSearch\Indexer |  |
| discard | .lazy.TYPO3\CMS\Core\Site\Set\SetCollector | mutable | mutable: sets,invalidSets |
| discard | .lazy.TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface.073HiA2 | mutable | mutable: request,configuration,feConfigCache |
| discard | .lazy.TYPO3\CMS\Form\Domain\Configuration\ConfigurationService | mutable | mutable: extbaseConfigurationManager,extFormConfigurationManager,translationService,assetsCache,runtimeCache |
| discard | .service_locator.MQj6dX1 | mutable | mutable: externalId,container,loading,providedTypes,factories |
| discard | Masterminds\HTML5 | mutable | mutable: defaultOptions,errors |
| discard | Ochorocho\FrankenPhp\Command\AuditCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | Ochorocho\FrankenPhp\Command\InitCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | Psr\Http\Client\ClientInterface | mutable | mutable: config |
| discard | Symfony\Component\Console\Command\DumpCompletionCommand | mutable | mutable: supportedShells,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | Symfony\Component\Console\Command\HelpCommand | mutable | mutable: command,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport | mutable | mutable: receiver,sender,connection,serializer |
| discard | Symfony\Component\Messenger\Middleware\HandleMessageMiddleware | mutable | mutable: handlersLocator,allowNoHandlers,logger |
| discard | Symfony\Component\Messenger\Middleware\SendMessageMiddleware | mutable | mutable: sendersLocator,eventDispatcher,allowNoSenders,logger |
| discard | Symfony\Component\Messenger\Transport\Sync\SyncTransport | mutable | mutable: messageBus |
| discard | Symfony\Component\Translation\Translator | mutable | mutable: catalogues,locale,fallbackLocales,loaders,resources,formatter,configCacheFactory,parentLocales,hasIntlFormatter,globalParameters,globalTranslatedParameters,cacheDir,debug,cacheVary |
| discard | Symfony\Component\Yaml\Command\LintCommand | mutable | mutable: parser,format,displayCorrectFiles,directoryIteratorProvider,isReadableProvider,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Adminpanel\Modules\CacheModule | mutable | mutable: subModules,mainConfiguration,configurationService,moduleData |
| discard | TYPO3\CMS\Adminpanel\Modules\DebugModule | mutable | mutable: subModules,mainConfiguration,configurationService,moduleData |
| discard | TYPO3\CMS\Adminpanel\Modules\Debug\Log | mutable | mutable: logLevel |
| discard | TYPO3\CMS\Adminpanel\Modules\InfoModule | mutable | mutable: subModules,mainConfiguration,configurationService,moduleData |
| discard | TYPO3\CMS\Adminpanel\Modules\PreviewModule | mutable | mutable: config,subModules,mainConfiguration,configurationService,moduleData |
| discard | TYPO3\CMS\Adminpanel\Modules\TsDebugModule | mutable | mutable: subModules,mainConfiguration,configurationService,moduleData |
| discard | TYPO3\CMS\Adminpanel\Modules\TsDebug\TypoScriptWaterfall | mutable | mutable: printConf,highlightLongerThan |
| discard | TYPO3\CMS\Backend\CodeEditor\CodeEditor | mutable | mutable: configuration |
| discard | TYPO3\CMS\Backend\Command\CreateBackendUserCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Backend\Command\DebugBackendModulesCommand | mutable | mutable: languageService,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Backend\Command\DebugBackendRoutesCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Backend\Command\LockBackendCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Backend\Command\ReferenceIndexUpdateCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Backend\Command\ResetPasswordCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Backend\Command\UnlockBackendCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Backend\ContextMenu\ContextMenu | mutable | mutable: itemProvidersRegistry |
| discard | TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider | mutable | mutable: languageService,backendUser,clipboard,itemsConfiguration,disabledItems,table,identifier,context |
| discard | TYPO3\CMS\Backend\ContextMenu\ItemProviders\PageProvider | mutable | mutable: table,itemsConfiguration,languageAccess,record,pageRecord,pagePermissions,itemsConfiguration,languageService,backendUser,clipboard,itemsConfiguration,disabledItems,table,identifier,context |
| discard | TYPO3\CMS\Backend\ContextMenu\ItemProviders\RecordProvider | mutable | mutable: record,pageRecord,pagePermissions,itemsConfiguration,languageService,backendUser,clipboard,itemsConfiguration,disabledItems,table,identifier,context |
| discard | TYPO3\CMS\Backend\ContextMenu\ItemProviders\SiteSettingsProvider | mutable | mutable: languageService,backendUser,clipboard,itemsConfiguration,disabledItems,table,identifier,context |
| discard | TYPO3\CMS\Backend\EventListener\FailedLoginAttemptNotification | mutable | mutable: notificationRecipientEmailAddress |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseDefaultLanguagePageRow | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEditRow | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseParentPageRow | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\InlineOverrideChildTca | mutable | mutable: notSettableFields,configurationKeysForNotSettableFields |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\SiteTcaInline | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaCategory | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaCheckboxItems | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaCountry | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaFiles | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaInline | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaJson | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaLanguage | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaRadioItems | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectItems | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectTreeItems | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaSiteLanguage | mutable | mutable: connectionPool,logger |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaTablePermission | mutable | mutable: iconFactory,fileRepository,flashMessageService,connectionPool,itemProcessingService,envPlaceholderProcessor |
| discard | TYPO3\CMS\Backend\Form\FormDataProvider\TcaTypesCtrlOverrides | mutable | mutable: allowedCtrlOverrides |
| discard | TYPO3\CMS\Backend\Form\NodeFactory | mutable | mutable: nodeResolver,nodeTypes |
| discard | TYPO3\CMS\Backend\History\RecordHistory | mutable | mutable: maxSteps,showSubElements,element,lastHistoryEntry,pageAccessCache |
| discard | TYPO3\CMS\Backend\Http\Application | mutable | mutable: requestHandler,logger |
| discard | TYPO3\CMS\Backend\Http\RequestHandler | mutable | mutable: dispatcher,uriBuilder,listenerProvider |
| discard | TYPO3\CMS\Backend\Middleware\BackendUserAuthenticator | mutable | mutable: publicRoutes,languageServiceFactory,rateLimiterFactory,logger,context |
| discard | TYPO3\CMS\Backend\Middleware\PageContextInitialization | mutable | mutable: logger |
| discard | TYPO3\CMS\Backend\Middleware\SudoModeInterceptor | mutable | mutable: currentRequest |
| discard | TYPO3\CMS\Backend\Preview\RecordFieldPreviewProcessor | mutable | mutable: itemLabels |
| discard | TYPO3\CMS\Backend\Preview\StandardContentPreviewRenderer | mutable | mutable: fieldProcessor,tcaSchemaFactory,localizationRepository,backendLayoutView,logger |
| discard | TYPO3\CMS\Backend\Search\LiveSearch\BackendModuleProvider | mutable | mutable: languageService |
| discard | TYPO3\CMS\Backend\Search\LiveSearch\DatabaseRecordProvider | mutable | mutable: languageService,userPermissions,pageIdList |
| discard | TYPO3\CMS\Backend\Search\LiveSearch\PageRecordProvider | mutable | mutable: languageService,userPermissions,pageIdList |
| discard | TYPO3\CMS\Backend\Search\LiveSearch\QueryParser | mutable | mutable: commandKey,tableName |
| discard | TYPO3\CMS\Backend\Security\CategoryPermissionsAspect | mutable | mutable: categoryTableName |
| discard | TYPO3\CMS\Backend\Security\EmailLoginNotification | mutable | mutable: warningMode,warningEmailRecipient,request |
| discard | TYPO3\CMS\Backend\Security\SudoMode\Access\AccessStorage | mutable | mutable: logger |
| discard | TYPO3\CMS\Backend\Toolbar\ToolbarItemsRegistry | mutable | mutable: toolbarItems |
| discard | TYPO3\CMS\Backend\Tree\View\ContentCreationPagePositionMap | mutable | mutable: defVals,saveAndClose,R_URI,iconFactory,uriBuilder,cur_sys_language,backendLayoutView |
| discard | TYPO3\CMS\Backend\Tree\View\ContentMovingPagePositionMap | mutable | mutable: moveUid,copyMode,iconFactory,cur_sys_language,backendLayoutView |
| discard | TYPO3\CMS\Backend\View\BackendLayout\DataProviderCollection | mutable | mutable: dataProviders,results |
| discard | TYPO3\CMS\Backend\View\BackendLayout\PageTsBackendLayoutDataProvider | mutable | mutable: backendLayouts |
| discard | TYPO3\CMS\Backend\Wizard\PageWizardProvider | mutable | mutable: uriBuilder,stepFactory |
| discard | TYPO3\CMS\Beuser\Domain\Repository\BackendUserGroupRepository | mutable | mutable: defaultOrderings,persistenceManager,eventDispatcher,autoTagging,objectType,defaultOrderings,defaultQuerySettings |
| discard | TYPO3\CMS\Beuser\Domain\Repository\BackendUserRepository | mutable | mutable: persistenceManager,eventDispatcher,autoTagging,objectType,defaultOrderings,defaultQuerySettings |
| discard | TYPO3\CMS\Beuser\Domain\Repository\BackendUserSessionRepository | mutable | mutable: sessionBackend |
| discard | TYPO3\CMS\Beuser\Domain\Repository\FileMountRepository | mutable | mutable: persistenceManager,eventDispatcher,autoTagging,objectType,defaultOrderings,defaultQuerySettings |
| discard | TYPO3\CMS\Core\Authentication\Mfa\MfaProviderRegistry | mutable | mutable: providers |
| discard | TYPO3\CMS\Core\Command\AssetPublishCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\CacheFlushCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\CacheFlushTagsCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\CacheWarmupCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\ConsumeMessagesCommand | mutable | mutable: worker,logger,receiverNames,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\DumpAutoloadCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\ExtensionListCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\ListCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\SendEmailCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\SetupExtensionsCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\SiteListCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\SiteSetsListCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\SiteShowCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\UpdateLanguagePackCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\UpgradeWizardListCommand | mutable | mutable: upgradeWizardsService,output,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\UpgradeWizardMarkUndoneCommand | mutable | mutable: upgradeWizardsService,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Command\UpgradeWizardRunCommand | mutable | mutable: upgradeWizardsService,output,input,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Core\Composer\PackageArtifactBuilder | mutable | mutable: event,config,fileSystem,installedTypo3Extensions,dependencyOrderingService,packageCache,packagesBasePaths,packageAliasMap,packagesBasePath,packages,availablePackagesScanned,composerNameToPackageKeyMap,activePackages,packageStatesPathAndFilename,packageStatesConfiguration,packagePathMatchRegex,frameworkPackageNames,installedPackageNames |
| discard | TYPO3\CMS\Core\Configuration\Processor\Placeholder\EnvPlaceholderProcessor | mutable | mutable: processorList |
| discard | TYPO3\CMS\Core\Console\CommandApplication | mutable | mutable: context,commandRegistry,configurationManager,bootService,languageServiceFactory,application |
| discard | TYPO3\CMS\Core\Core\BootService | mutable | mutable: containerBuilder,failsafeContainer,container |
| discard | TYPO3\CMS\Core\DataHandling\SoftReference\EmailSoftReferenceParser | mutable | mutable: tokenID_basePrefix,parserKey,parameters |
| discard | TYPO3\CMS\Core\DataHandling\SoftReference\ExtensionPathSoftReferenceParser | mutable | mutable: parserKey,parameters |
| discard | TYPO3\CMS\Core\DataHandling\SoftReference\SubstituteSoftReferenceParser | mutable | mutable: tokenID_basePrefix,parserKey,parameters |
| discard | TYPO3\CMS\Core\DataHandling\SoftReference\TypolinkSoftReferenceParser | mutable | mutable: eventDispatcher,tokenID_basePrefix,parserKey,parameters |
| discard | TYPO3\CMS\Core\DataHandling\SoftReference\TypolinkTagSoftReferenceParser | mutable | mutable: eventDispatcher,tokenID_basePrefix,parserKey,parameters |
| discard | TYPO3\CMS\Core\DataHandling\SoftReference\UrlSoftReferenceParser | mutable | mutable: tokenID_basePrefix,parserKey,parameters |
| discard | TYPO3\CMS\Core\Database\ReferenceIndex | mutable | mutable: excludedTables,tableRelationFieldCache |
| discard | TYPO3\CMS\Core\Database\Schema\SqlReader | mutable | mutable: eventDispatcher,packageManager |
| discard | TYPO3\CMS\Core\Domain\Repository\PageRepository | mutable | mutable: computedPropertyNames,context,tcaSchemaFactory,pageTypeLinkResolver,logger |
| discard | TYPO3\CMS\Core\Error\DebugExceptionHandler | mutable | mutable: logExceptionStackTrace,logExceptionStackTrace,logger |
| discard | TYPO3\CMS\Core\Error\ProductionExceptionHandler | mutable | mutable: defaultTitle,defaultMessage,logExceptionStackTrace,logger |
| discard | TYPO3\CMS\Core\ExpressionLanguage\DefaultProvider | mutable | mutable: expressionLanguageProviders,expressionLanguageVariables |
| discard | TYPO3\CMS\Core\Html\DefaultSanitizerBuilder | mutable | mutable: behavior,globalAttrs,hrefAttr,srcAttr,srcsetAttr |
| discard | TYPO3\CMS\Core\Html\PreviewSanitizerBuilder | mutable | mutable: globalAttrs,hrefAttr,srcAttr,srcsetAttr |
| discard | TYPO3\CMS\Core\Html\RteHtmlParser | mutable | mutable: blockElementList,defaultAllowedTagsList,procOptions,TS_transform_db_safecounter,getKeepTags_cache,allowedClasses,allowedAttributesForParagraphTags,allowedTagsOutsideOfParagraphs,logger |
| discard | TYPO3\CMS\Core\Http\Application | mutable | mutable: requestHandler,logger |
| discard | TYPO3\CMS\Core\Information\Typo3Information | mutable | mutable: languageService |
| discard | TYPO3\CMS\Core\LinkHandling\LinkService | mutable | mutable: handlers |
| discard | TYPO3\CMS\Core\Localization\LanguagePackService | mutable | mutable: locales,registry |
| discard | TYPO3\CMS\Core\Locking\LockFactory | mutable | mutable: lockingStrategy |
| discard | TYPO3\CMS\Core\Locking\ResourceMutex | mutable | mutable: accessLocks,workerLocks |
| discard | TYPO3\CMS\Core\Messaging\FlashMessageRendererResolver | mutable | mutable: renderer |
| discard | TYPO3\CMS\Core\Middleware\CacheDataCollectorAttribute | mutable | mutable: cacheDataCollector |
| discard | TYPO3\CMS\Core\Middleware\RequestTokenMiddleware | mutable | mutable: securityAspect,noncePool,logger |
| discard | TYPO3\CMS\Core\Middleware\VerifyHostHeader | mutable | mutable: trustedHostsPattern |
| discard | TYPO3\CMS\Core\Package\FailsafePackageManager | mutable | mutable: inFailsafeMode,dependencyOrderingService,packageCache,packagesBasePaths,packageAliasMap,packagesBasePath,packages,availablePackagesScanned,composerNameToPackageKeyMap,activePackages,packageStatesPathAndFilename,packageStatesConfiguration,packagePathMatchRegex,frameworkPackageNames,installedPackageNames |
| discard | TYPO3\CMS\Core\Package\PackageActivationService | mutable | mutable: packageManager,bootService,opcodeCacheService |
| discard | TYPO3\CMS\Core\PageTitle\RecordPageTitleProvider | mutable | mutable: request,title |
| discard | TYPO3\CMS\Core\PageTitle\RecordTitleProvider | mutable | mutable: request,title |
| discard | TYPO3\CMS\Core\Page\PageRenderer | mutable | mutable: moveJsFromHeaderToFooter,locale,jsFiles,jsLibs,cssFiles,cssLibs,title,favIcon,xmlPrologAndDocType,inlineComments,headerData,footerData,titleTag,htmlTag,headTag,iconMimeType,shortcutTag,jsInline,cssInline,bodyContent,templateFile,inlineLanguageLabels,inlineLanguageLabelFiles,inlineSettings,endingSlash,javaScriptRenderer,nonce,docType,applyNonceHint |
| discard | TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage | mutable | mutable: cacheInstance |
| discard | TYPO3\CMS\Core\Resource\MimeTypeDetector | mutable | mutable: collection |
| discard | TYPO3\CMS\Core\Resource\OnlineMedia\Processing\PreviewProcessing | mutable | mutable: logger |
| discard | TYPO3\CMS\Core\Resource\Rendering\AudioTagRenderer | mutable | mutable: possibleMimeTypes |
| discard | TYPO3\CMS\Core\Resource\Rendering\VideoTagRenderer | mutable | mutable: possibleMimeTypes,excludeAttributes |
| discard | TYPO3\CMS\Core\Resource\Rendering\VimeoRenderer | mutable | mutable: onlineMediaHelper |
| discard | TYPO3\CMS\Core\Resource\Rendering\YouTubeRenderer | mutable | mutable: onlineMediaHelper |
| discard | TYPO3\CMS\Core\Resource\Security\SvgEventListener | mutable | mutable: sanitizer,typeCheck |
| discard | TYPO3\CMS\Core\Resource\Security\SvgHookHandler | mutable | mutable: sanitizer,typeCheck |
| discard | TYPO3\CMS\Core\Resource\Security\SvgTypeCheck | mutable | mutable: mimeTypeDetector,fileExtensions |
| discard | TYPO3\CMS\Core\Resource\Service\ResourceConsistencyService | mutable | mutable: exceptionItems |
| discard | TYPO3\CMS\Core\Routing\BackendEntryPointResolver | mutable | mutable: entryPoint |
| discard | TYPO3\CMS\Core\Routing\Enhancer\VariableProcessorCache | mutable | mutable: requiresHashing,hashes |
| discard | TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationRepository | mutable | mutable: resolvedMutations |
| discard | TYPO3\CMS\Core\Service\SilentConfigurationUpgradeService | mutable | mutable: obsoleteLocalConfigurationSettings |
| discard | TYPO3\CMS\Core\Service\UpgradeWizardsService | mutable | mutable: output |
| discard | TYPO3\CMS\Core\Site\Set\SetCollector_decorated_33 | mutable | mutable: sets,invalidSets |
| discard | TYPO3\CMS\Core\Site\SiteLanguagePresets | mutable | mutable: presets |
| discard | TYPO3\CMS\Core\TypoScript\AST\AstBuilder | mutable | mutable: flatConstants,eventDispatcher |
| discard | TYPO3\CMS\Core\TypoScript\AST\CommentAwareAstBuilder | mutable | mutable: flatConstants,eventDispatcher |
| discard | TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateTreeBuilder | mutable | mutable: includedSysTemplateUids,type,tokenizer,cache,enableStaticMagicIncludes |
| discard | TYPO3\CMS\Core\TypoScript\IncludeTree\TreeFromLineStreamBuilder | mutable | mutable: type,tokenizer,enableMagicIncludes,atImportTypeToSuffixMap |
| discard | TYPO3\CMS\Core\TypoScript\Tokenizer\LosslessTokenizer | mutable | mutable: lineStream,tokenStream,identifierStream,valueStream,lines,currentLineNumber,currentLineString,currentLinebreakCallback,currentColumnInLine |
| discard | TYPO3\CMS\Core\TypoScript\Tokenizer\LossyTokenizer | mutable | mutable: lineStream,identifierStream,valueStream,lines,currentLineNumber,currentLineString |
| discard | TYPO3\CMS\Core\Upgrades\DatabaseRowsUpdateWizard | mutable | mutable: rowUpdater |
| discard | TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite | mutable | mutable: output |
| discard | TYPO3\CMS\Core\Upgrades\PageDoktypeLinkMigration | mutable | mutable: output |
| discard | TYPO3\CMS\Core\Upgrades\ReferenceIndexUpdatedPrerequisite | mutable | mutable: output |
| discard | TYPO3\CMS\Core\Upgrades\UserPermissionsForRenamedModulesMigration | mutable | mutable: tables,moduleRenaming,requiredParentModules |
| discard | TYPO3\CMS\Core\Utility\File\ExtendedFileUtility | mutable | mutable: existingFilesConflictMode,actionPerms,internalUploadMap,flashMessages,fileCmdMap,uploadedFiles,fileFactory,maxNumber,uniquePrecision |
| discard | TYPO3\CMS\Dashboard\DashboardPresetRegistry_decorated_1 | mutable | mutable: dashboardPresets |
| discard | TYPO3\CMS\Dashboard\Repository\DashboardRepository | mutable | mutable: allowedFields,widgets |
| discard | TYPO3\CMS\Dashboard\WidgetGroupRegistry_decorated_1 | mutable | mutable: widgetGroups |
| discard | TYPO3\CMS\Dashboard\WidgetRegistry | mutable | mutable: widgets,widgetsPerWidgetGroup |
| discard | TYPO3\CMS\Extbase\Mvc\Controller\AuthorizeRegistry | mutable | mutable: authorizations |
| discard | TYPO3\CMS\Extbase\Mvc\Controller\MvcPropertyMappingConfigurationService | mutable | mutable: hashService |
| discard | TYPO3\CMS\Extbase\Mvc\Controller\RateLimitRegistry | mutable | mutable: rateLimits |
| discard | TYPO3\CMS\Extbase\Mvc\Dispatcher | mutable | mutable: container,eventDispatcher |
| discard | TYPO3\CMS\Extbase\Persistence\Repository | mutable | mutable: persistenceManager,eventDispatcher,autoTagging,objectType,defaultOrderings,defaultQuerySettings |
| discard | TYPO3\CMS\Extbase\Property\PropertyMapper | mutable | mutable: messages,typeConverterRegistry,configurationBuilder |
| discard | TYPO3\CMS\Extbase\Property\TypeConverter\CountryConverter | mutable | mutable: countryProvider |
| discard | TYPO3\CMS\Extbase\Property\TypeConverter\FileConverter | mutable | mutable: expectedObjectType,expectedObjectType,fileFactory |
| discard | TYPO3\CMS\Extbase\Property\TypeConverter\FileReferenceConverter | mutable | mutable: expectedObjectType,expectedObjectType,fileFactory |
| discard | TYPO3\CMS\Extbase\Property\TypeConverter\FolderConverter | mutable | mutable: expectedObjectType,expectedObjectType,fileFactory |
| discard | TYPO3\CMS\Extbase\Property\TypeConverter\ObjectConverter | mutable | mutable: container,reflectionService |
| discard | TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter | mutable | mutable: persistenceManager,container,reflectionService |
| discard | TYPO3\CMS\Extbase\Service\ExtensionService | mutable | mutable: configurationManager,targetPidPluginCache |
| discard | TYPO3\CMS\Extensionmanager\Command\ActivateExtensionCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Extensionmanager\Command\DeactivateExtensionCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Extensionmanager\Domain\Model\DownloadQueue | mutable | mutable: extensionStorage,extensionInstallStorage |
| discard | TYPO3\CMS\Extensionmanager\Domain\Repository\BulkExtensionRepositoryWriter | mutable | mutable: parser,sumRecords,arrRows,maxRowsPerChunk,remoteIdentifier,extensionRepository,extensionModel,connectionPool,minimumDateToImport; static: fieldNames |
| discard | TYPO3\CMS\Extensionmanager\Package\ComposerDeficitDetector | mutable | mutable: availableExtensions |
| discard | TYPO3\CMS\Extensionmanager\Remote\RemoteRegistry | mutable | mutable: remotes,defaultRemote |
| discard | TYPO3\CMS\Extensionmanager\Service\ComposerManifestProposalGenerator | mutable | mutable: requestFactory,emConfUtility |
| discard | TYPO3\CMS\Extensionmanager\Service\ExtensionManagementService | mutable | mutable: dependencyUtility,installUtility,automaticInstallationEnabled,skipDependencyCheck |
| discard | TYPO3\CMS\Extensionmanager\Utility\DependencyUtility | mutable | mutable: extensionRepository,listUtility,emConfUtility,managementService,availableExtensions,dependencyErrors,skipDependencyCheck |
| discard | TYPO3\CMS\Extensionmanager\Utility\FileHandlingUtility | mutable | mutable: languageService,logger |
| discard | TYPO3\CMS\Extensionmanager\Utility\InstallUtility | mutable | mutable: languageService,logger |
| discard | TYPO3\CMS\Extensionmanager\Utility\ListUtility | mutable | mutable: emConfUtility,extensionRepository,packageManager,availableExtensions,eventDispatcher,resourceFactory,resourcePublisher,dependencyUtility |
| discard | TYPO3\CMS\Filelist\ContextMenu\ItemProviders\FileProvider | mutable | mutable: record,itemsConfiguration,languageService,backendUser,clipboard,itemsConfiguration,disabledItems,table,identifier,context |
| discard | TYPO3\CMS\Filelist\ElementBrowser\CreateFileBrowser | mutable | mutable: identifier,expandFolder,currentPage,moduleStorageIdentifier,filelist,sortField,sortDirection,viewMode,displayThumbs,selectedFolder,resourceDisplayMatcher,resourceSelectableMatcher,identifier,browserParameters,bparams,request,view |
| discard | TYPO3\CMS\Filelist\ElementBrowser\CreateFolderBrowser | mutable | mutable: identifier,expandFolder,currentPage,moduleStorageIdentifier,filelist,sortField,sortDirection,viewMode,displayThumbs,selectedFolder,resourceDisplayMatcher,resourceSelectableMatcher,identifier,browserParameters,bparams,request,view |
| discard | TYPO3\CMS\Fluid\Command\AnalyzeCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Fluid\Command\NamespacesCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Fluid\Command\SchemaCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Fluid\Command\WarmupCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Fluid\ViewHelpers\ResourceViewHelper_decorated_1 | mutable | mutable: argumentDefinitions,viewHelperNode,arguments,templateVariableContainer,renderingContext,renderingContextStack,renderChildrenClosure,viewHelperVariableContainer,escapeChildren,escapeOutput; static: argumentDefinitionCache |
| discard | TYPO3\CMS\Fluid\ViewHelpers\Uri\ResourceViewHelper_decorated_1 | mutable | mutable: argumentDefinitions,viewHelperNode,arguments,templateVariableContainer,renderingContext,renderingContextStack,renderChildrenClosure,viewHelperVariableContainer,escapeChildren,escapeOutput; static: argumentDefinitionCache |
| discard | TYPO3\CMS\Form\Command\CleanupFormUploadsCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Form\Command\TransferFormDefinitionCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Form\Domain\Configuration\ConfigurationService | mutable | mutable: extbaseConfigurationManager,extFormConfigurationManager,translationService,assetsCache,runtimeCache |
| discard | TYPO3\CMS\Form\Hooks\FormFileProvider | mutable | mutable: itemsConfiguration,record,itemsConfiguration,languageService,backendUser,clipboard,itemsConfiguration,disabledItems,table,identifier,context |
| discard | TYPO3\CMS\Form\Hooks\FormPagePreviewRenderer | mutable | mutable: fieldProcessor,tcaSchemaFactory,localizationRepository,fieldProcessor,tcaSchemaFactory,localizationRepository,backendLayoutView,logger |
| discard | TYPO3\CMS\Form\Mvc\Configuration\FormYamlCollector | mutable | mutable: configurations |
| discard | TYPO3\CMS\Form\Mvc\Property\TypeConverter\UploadedFileReferenceConverter | mutable | mutable: defaultUploadFolder,defaultConflictMode,convertedResources,resourceFactory,hashService,persistenceManager,storageRepository,logger |
| discard | TYPO3\CMS\Form\SoftReference\FormPersistenceIdentifierSoftReferenceParser | mutable | mutable: tokenID_basePrefix,parserKey,parameters |
| discard | TYPO3\CMS\Form\Storage\ExtensionStorageAdapter | mutable | mutable: storageRepository |
| discard | TYPO3\CMS\Form\Storage\FileMountStorageAdapter | mutable | mutable: storageRepository |
| discard | TYPO3\CMS\Form\Storage\Security\FormDefinitionPersistenceGuard | mutable | mutable: allowedInvocations |
| discard | TYPO3\CMS\FrontendLogin\Configuration\RecoveryConfiguration | mutable | mutable: forgotHash,replyTo,sender,settings,mailTemplateName,timestamp,context,logger |
| discard | TYPO3\CMS\FrontendLogin\Redirect\RedirectHandler | mutable | mutable: userIsLoggedIn,redirectModeHandler,redirectUrlValidator |
| discard | TYPO3\CMS\FrontendLogin\Service\RecoveryService | mutable | mutable: settings,eventDispatcher,recoveryConfiguration,uriBuilder |
| discard | TYPO3\CMS\Frontend\ContentObject\ContentObjectFactory | mutable | mutable: contentObjectLocator |
| discard | TYPO3\CMS\Frontend\Html\HtmlWorker | mutable | mutable: mount,document |
| discard | TYPO3\CMS\Frontend\Http\Application | mutable | mutable: requestHandler,logger |
| discard | TYPO3\CMS\Frontend\Middleware\BackendUserAuthenticator | mutable | mutable: context |
| discard | TYPO3\CMS\Frontend\Middleware\FrontendUserAuthenticator | mutable | mutable: logger |
| discard | TYPO3\CMS\Frontend\Middleware\ShortcutAndMountPointRedirect | mutable | mutable: logger |
| discard | TYPO3\CMS\Frontend\Middleware\TimeTrackerInitialization | mutable | mutable: isDebugEnabledInTypoScriptConfig |
| discard | TYPO3\CMS\Frontend\Page\CacheHashCalculator | mutable | mutable: configuration,hashService |
| discard | TYPO3\CMS\Frontend\Typolink\EmailLinkBuilder | mutable | mutable: logger |
| discard | TYPO3\CMS\Frontend\Typolink\ExternalUrlLinkBuilder | mutable | mutable: contentObjectRenderer |
| discard | TYPO3\CMS\Frontend\Typolink\FileOrFolderLinkBuilder | mutable | mutable: contentObjectRenderer |
| discard | TYPO3\CMS\Frontend\Typolink\LegacyLinkBuilder | mutable | mutable: contentObjectRenderer |
| discard | TYPO3\CMS\Frontend\Typolink\PageLinkBuilder | mutable | mutable: contentObjectRenderer,contentObjectRenderer |
| discard | TYPO3\CMS\Impexp\Command\ExportCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Impexp\Command\ImportCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Impexp\ContextMenu\ItemProvider | mutable | mutable: itemsConfiguration,languageService,backendUser,clipboard,itemsConfiguration,disabledItems,table,identifier,context |
| discard | TYPO3\CMS\Impexp\Utility\ImportExportUtility | mutable | mutable: import,eventDispatcher,importSiteConfigurations |
| discard | TYPO3\CMS\IndexedSearch\Domain\Repository\AdministrationRepository | mutable | mutable: external_parsers,allPhashListed,iconFileNameCache |
| discard | TYPO3\CMS\IndexedSearch\Lexer | mutable | mutable: lexerConf |
| discard | TYPO3\CMS\Install\Command\PasswordSetCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Install\Command\SetupCommand | mutable | mutable: connectionLabels,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Install\Command\SetupDefaultBackendUserGroupsCommand | mutable | mutable: userGroupEnum,availableUserGroups,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Install\Database\PermissionsCheck | mutable | mutable: testTableName,messages |
| discard | TYPO3\CMS\Install\Http\Application | mutable | mutable: requestHandler,logger |
| discard | TYPO3\CMS\Install\Middleware\Maintenance | mutable | mutable: controllers |
| discard | TYPO3\CMS\Install\Service\CoreUpdateService | mutable | mutable: messages,downloadTargetPath,symlinkToCoreFiles,downloadBaseUri |
| discard | TYPO3\CMS\Install\Service\CoreVersionService | mutable | mutable: apiBaseUrl |
| discard | TYPO3\CMS\Install\Service\LateBootService | mutable | mutable: containerBuilder,failsafeContainer,container |
| discard | TYPO3\CMS\Install\Service\SessionService | mutable | mutable: cookieName,expireTimeInMinutes,regenerateSessionIdTime |
| discard | TYPO3\CMS\Install\Service\SetupDatabaseService | mutable | mutable: validDrivers |
| discard | TYPO3\CMS\Install\Service\WebServerConfigurationFileService | mutable | mutable: webServer,publicPath |
| discard | TYPO3\CMS\Install\SystemEnvironment\ServerResponse\ServerResponseCheck | mutable | mutable: messageQueue,assetLocation,fileadminLocation,fileDeclarations |
| discard | TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite | mutable | mutable: output |
| discard | TYPO3\CMS\Install\Updates\ReferenceIndexUpdatedPrerequisite | mutable | mutable: output |
| discard | TYPO3\CMS\Linkvalidator\LinkAnalyzer | mutable | mutable: searchFields,pids,linkCounts,brokenLinkCounts,tsConfig |
| discard | TYPO3\CMS\Linkvalidator\Linktype\ExternalLinktype | mutable | mutable: urlReports,urlErrorParams,headers,allowRedirects,method,range,timeout,identifier,errorParams,identifier |
| discard | TYPO3\CMS\Linkvalidator\Linktype\FileLinktype | mutable | mutable: identifier,errorParams,identifier |
| discard | TYPO3\CMS\Linkvalidator\Linktype\InternalLinktype | mutable | mutable: responsePage,responseContent,identifier,errorParams,identifier |
| discard | TYPO3\CMS\Linkvalidator\Linktype\LinktypeRegistry | mutable | mutable: linktypes |
| discard | TYPO3\CMS\Linkvalidator\Result\LinkAnalyzerResult | mutable | mutable: brokenLinks,newBrokenLinkCounts,oldBrokenLinkCounts,differentToLastResult,localizedPages |
| discard | TYPO3\CMS\Lowlevel\Command\CleanFlexFormsCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\CleanUpLocalProcessedFilesCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\ConfigurationRemoveCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\ConfigurationSetCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\ConfigurationShowCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\DeletedRecordsCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\ListSysLogCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\MissingRelationsCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\OrphanRecordsCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\TranslationDomainListCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\Command\TranslationDomainSearchCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\ProviderRegistry | mutable | mutable: providers |
| discard | TYPO3\CMS\Lowlevel\Integrity\DatabaseIntegrityCheck | mutable | mutable: pageIdArray,pageTranslatedPageIDArray,recStats,lRecords,lostPagesList |
| discard | TYPO3\CMS\Redirects\Command\CheckIntegrityCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Redirects\Command\CleanupRedirectsCommand | mutable | mutable: languageService,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Redirects\Hooks\DataHandlerSlugUpdateHook | mutable | mutable: persistedChangedItems,slugService,slugRedirectChangeItemFactory |
| discard | TYPO3\CMS\Redirects\Http\Middleware\RedirectHandler | mutable | mutable: redirectService,eventDispatcher,responseFactory,logger |
| discard | TYPO3\CMS\Redirects\Repository\RedirectRepository | mutable | mutable: schema |
| discard | TYPO3\CMS\Redirects\Security\RedirectPermissionGuard | mutable | mutable: allowedHosts |
| discard | TYPO3\CMS\Redirects\Service\RedirectCacheService | mutable | mutable: cache |
| discard | TYPO3\CMS\Scheduler\Command\SchedulerCommand | mutable | mutable: io,overwrittenTaskList,stopTasks,forceExecution,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Scheduler\Command\SchedulerExecuteCommand | mutable | mutable: io,scheduler,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Scheduler\Command\SchedulerListCommand | mutable | mutable: io,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Scheduler\Scheduler | mutable | mutable: logger,taskSerializer,schedulerTaskRepository,extConf |
| discard | TYPO3\CMS\Scheduler\SystemInformation\ToolbarItemProvider | mutable | mutable: lastRunInformation |
| discard | TYPO3\CMS\Seo\HrefLang\HrefLangGenerator | mutable | mutable: cObj,languageMenuProcessor |
| discard | TYPO3\CMS\Seo\PageTitle\SeoTitlePageTitleProvider | mutable | mutable: request,title |
| discard | TYPO3\CMS\SysNote\Renderer\NoteRenderer | mutable | mutable: pagePermissionCache |
| discard | TYPO3\CMS\Tstemplate\Hooks\DataHandlerClearCachePostProcHook | mutable | mutable: cacheManager |
| discard | TYPO3\CMS\Webhooks\MessageHandler\WebhookMessageHandler | mutable | mutable: algo |
| discard | TYPO3\CMS\Webhooks\Repository\WebhookRepository | mutable | mutable: cacheIdentifierPrefix,applyDefaultRestrictions |
| discard | TYPO3\CMS\Workspaces\Command\AutoPublishCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Workspaces\Command\CleanupPreviewLinksCommand | mutable | mutable: application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Workspaces\Command\WorkspaceVersionRecordsCommand | mutable | mutable: allWorkspaces,foundRecords,application,name,processTitle,aliases,definition,hidden,help,description,fullDefinition,ignoreValidationErrors,code,synopsis,usages,helperSet |
| discard | TYPO3\CMS\Workspaces\Hook\DataHandlerHook | mutable | mutable: notificationInfo,remappedIds |
| discard | TYPO3\CMS\Workspaces\Middleware\WorkspacePreview | mutable | mutable: previewNotificationEnabled,previewMessage |
| discard | TYPO3\CMS\Workspaces\Service\Dependency\CollectionService | mutable | mutable: dependencyResolver,dataArray,nestedDataArray |
| discard | container.env_var_processors_locator | mutable | mutable: externalId,container,loading,providedTypes,factories |
| discard | content.security.policies_decorated_33 | mutable | mutable: keys,values,index,length,end |
| discard | dashboard.widget.docGettingStarted | mutable | mutable: request |
| discard | dashboard.widget.docTSconfig | mutable | mutable: request |
| discard | dashboard.widget.docTypoScriptReference | mutable | mutable: request |
| discard | dashboard.widget.failedLogins | mutable | mutable: request |
| discard | dashboard.widget.pagesWithoutMetaDescription | mutable | mutable: request |
| discard | dashboard.widget.sysLogErrors | mutable | mutable: request |
| discard | dashboard.widget.t3information | mutable | mutable: request |
| discard | dashboard.widget.typeOfUsers | mutable | mutable: request |
| discard | lowlevel.configuration.module.provider.formyamlconfiguration | mutable | mutable: identifier |
| discard | lowlevel.configuration.module.provider.reactions | mutable | mutable: identifier,label |
| discard | lowlevel.configuration.module.provider.webhooks | mutable | mutable: identifier,label |
| discard | .lazy.TYPO3\CMS\Form\Mvc\Configuration\ConfigurationManagerInterface.4HYN7sy | opaque |  |
| discard | TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentProcessorInterface_decorated_1 | opaque |  |
| discard | TYPO3\CMS\Core\Adapter\EventDispatcherAdapter | opaque |  |
| discard | TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface_decorated_1 | opaque |  |
| discard | TYPO3\CMS\Core\View\ViewFactoryInterface_decorated_1 | opaque |  |
| discard | TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface | opaque |  |
| discard | messenger.bus.default | opaque |  |
| discard | Ochorocho\FrankenPhp\Controller\Backend\MetricsAjaxController | pattern |  |
| discard | TYPO3\CMS\Adminpanel\Controller\AjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Backend\ToolbarItems\BookmarkToolbarItem | pattern | mutable: request |
| discard | TYPO3\CMS\Backend\Backend\ToolbarItems\ClearCacheToolbarItem | pattern | mutable: cacheActions,optionValues,request |
| discard | TYPO3\CMS\Backend\Backend\ToolbarItems\LiveSearchToolbarItem | pattern | mutable: request |
| discard | TYPO3\CMS\Backend\Backend\ToolbarItems\SystemInformationToolbarItem | pattern | mutable: request,systemInformation,highestSeverity,severityBadgeClass,systemMessages,systemMessageTotalCount |
| discard | TYPO3\CMS\Backend\Backend\ToolbarItems\UserToolbarItem | pattern | mutable: request |
| discard | TYPO3\CMS\Backend\Controller\AboutController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\AjaxLoginController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\BackendController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\BookmarkController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\ClipboardController | pattern | mutable: responseFactory,streamFactory,clipboard |
| discard | TYPO3\CMS\Backend\Controller\CodeEditor\CodeCompletionController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\ColorSchemeController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\ColumnSelectorController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\ContentElement\ElementHistoryController | pattern | mutable: historyObject,showDiff,recordCache,view,returnUrl |
| discard | TYPO3\CMS\Backend\Controller\ContentElement\ElementInformationController | pattern | mutable: type,row,table,fileObject,folderObject |
| discard | TYPO3\CMS\Backend\Controller\ContentElement\MoveElementController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\ContentElement\NewContentElementController | pattern | mutable: id,uid_pid,pageInfo,sys_language,returnUrl,colPos |
| discard | TYPO3\CMS\Backend\Controller\ContextualRecordEditController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\DummyController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\EditDocumentController | pattern | mutable: editconf,columnsOnly,defVals,overrideVals,returnUrl,retUrl,returnNewPageId,popViewId,returnEditConf,pageinfo,elementsData,firstEl,numberOfErrors,module,tcaSchemaFactory |
| discard | TYPO3\CMS\Backend\Controller\ElementBrowserController | pattern | mutable: mode |
| discard | TYPO3\CMS\Backend\Controller\FileStorage\TreeController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\File\FileController | pattern | mutable: file,CB,overwriteExistingFiles,redirect,fileData |
| discard | TYPO3\CMS\Backend\Controller\File\ImageProcessController | pattern | mutable: imageProcessingService,logger |
| discard | TYPO3\CMS\Backend\Controller\FormFilesAjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\FormFlexAjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\FormInlineAjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\FormSelectTreeAjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\FormSlugAjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\JavaScriptLanguageDomainController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\LinkBrowserController | pattern | mutable: linkHandlers,currentLinkParts,currentLinkHandler,currentLinkHandlerId,displayedLinkHandler,displayedLinkHandlerId,linkAttributeFields,linkAttributeValues,parameters,dependencyOrderingService,pageRenderer,uriBuilder,extensionConfiguration,backendViewFactory,eventDispatcher |
| discard | TYPO3\CMS\Backend\Controller\LinkController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\LiveSearchController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\LoginController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\LogoutController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\MfaAjaxController | pattern | mutable: mfaProviderRegistry |
| discard | TYPO3\CMS\Backend\Controller\MfaConfigurationController | pattern | mutable: allowedActions,providerActionsWhenInactive,providerActionsWhenActive,view,mfaProviderRegistry,mfaTsConfig,mfaRequired,allowedProviders,allowedActions |
| discard | TYPO3\CMS\Backend\Controller\MfaController | pattern | mutable: mfaProviderRegistry,mfaTsConfig,mfaRequired,allowedProviders,allowedActions |
| discard | TYPO3\CMS\Backend\Controller\MfaSetupController | pattern | mutable: mfaProviderRegistry,mfaTsConfig,mfaRequired,allowedProviders,allowedActions |
| discard | TYPO3\CMS\Backend\Controller\NewRecordController | pattern | mutable: pageinfo,pidInfo,newRecordSortList,newPagesInto,newContentInto,newPagesAfter,newPagesSelectPosition,allowedNewTables,deniedNewTables,id,returnUrl,perms_clause,tRows,view,request |
| discard | TYPO3\CMS\Backend\Controller\OnlineMediaController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\PageLayoutController | pattern | mutable: pageContext,schema,moduleData |
| discard | TYPO3\CMS\Backend\Controller\PageTsConfig\PageTsConfigActiveController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\PageTsConfig\PageTsConfigIncludesController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\PageTsConfig\PageTsConfigRecordsOverviewController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Page\MovePageController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Page\NewMultiplePagesController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Page\SortSubPagesController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Page\TreeController | pattern | mutable: useNavTitle,addIdAsPrefix,addDomainName,showMountPathAboveMounts,hiddenRecords,labels,levelsToFetch,expandAllNodes,alternativeEntryPoints,pageTreeRepository,userHasAccessToModifyPagesAndToDefaultLanguage |
| discard | TYPO3\CMS\Backend\Controller\QrCodeController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\RecordListController | pattern | mutable: pageContext,table,searchTerm,returnUrl,modTSconfig,moduleData,allowClipboard,allowSearch |
| discard | TYPO3\CMS\Backend\Controller\RecordListDownloadController | pattern | mutable: id,table,format,filename,modTSconfig |
| discard | TYPO3\CMS\Backend\Controller\ResetPasswordController | pattern | mutable: loginProvider,view |
| discard | TYPO3\CMS\Backend\Controller\Resource\ResourceController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Security\SudoModeController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\SetupModuleController | pattern | mutable: overrideConf,languageUpdate,persistentUpdate,pagetreeNeedsRefresh,colorSchemeChanged,themeChanged,backendTitleFormatChanged,dateTimeFirstDayOfWeekChanged,tsFieldConf,passwordIsUpdated,passwordIsSubmitted,setupIsUpdated,settingsAreResetToDefault,passwordPolicyValidator |
| discard | TYPO3\CMS\Backend\Controller\SiteConfigurationController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\SiteInlineAjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\SiteSettingsController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\SubmoduleOverviewController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\SwitchUserController | pattern | mutable: eventDispatcher,uriBuilder,responseFactory,sessionBackend |
| discard | TYPO3\CMS\Backend\Controller\SystemInformationController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Wizard\AddController | pattern | mutable: processDataFlag,pid,table,id,P,returnEditConf |
| discard | TYPO3\CMS\Backend\Controller\Wizard\EditController | pattern | mutable: P,doClose,closeWindow |
| discard | TYPO3\CMS\Backend\Controller\Wizard\ImageManipulationController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Wizard\LocalizationController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Wizard\PageWizardController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Wizard\SuggestWizardController | pattern |  |
| discard | TYPO3\CMS\Backend\Controller\Wizard\WizardController | pattern |  |
| discard | TYPO3\CMS\Backend\Security\ContentSecurityPolicy\CspAjaxController | pattern |  |
| discard | TYPO3\CMS\Backend\Security\ContentSecurityPolicy\CspModuleController | pattern |  |
| discard | TYPO3\CMS\Beuser\Controller\PermissionController | pattern | mutable: id,returnUrl,depth,pageInfo |
| discard | TYPO3\CMS\Core\Controller\ErrorPageController | pattern |  |
| discard | TYPO3\CMS\Core\Controller\FileDumpController | pattern |  |
| discard | TYPO3\CMS\Core\Controller\IconController | pattern |  |
| discard | TYPO3\CMS\Core\Controller\PasswordGeneratorController | pattern |  |
| discard | TYPO3\CMS\Dashboard\Controller\DashboardAjaxController | pattern |  |
| discard | TYPO3\CMS\Dashboard\Controller\DashboardController | pattern |  |
| discard | TYPO3\CMS\Filelist\Controller\FileDownloadController | pattern | mutable: resourceFactory,responseFactory,streamFactory,context |
| discard | TYPO3\CMS\Filelist\Controller\FileListController | pattern | mutable: id,cmd,searchTerm,currentPage,allowClipboard,folderObject,overwriteExistingFiles,view,filelist,moduleData,logger |
| discard | TYPO3\CMS\Filelist\Controller\FileUpdateOnlineMediaController | pattern |  |
| discard | TYPO3\CMS\Filelist\Controller\File\EditFileController | pattern | mutable: dataColumnTca,formEngineData |
| discard | TYPO3\CMS\Frontend\Controller\ErrorController | pattern |  |
| discard | TYPO3\CMS\Frontend\Controller\ShowImageController | pattern | mutable: request,file,width,height,crop,frame,bodyTag,title,content |
| discard | TYPO3\CMS\Impexp\Controller\ExportController | pattern | mutable: defaultInputData |
| discard | TYPO3\CMS\Impexp\Controller\ImportController | pattern |  |
| discard | TYPO3\CMS\Info\Controller\PageInformationController | pattern |  |
| discard | TYPO3\CMS\Info\Controller\TranslationStatusController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\BackendModuleController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\EntryPointRedirectController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\EnvironmentController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\IconController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\InstallerController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\LayoutController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\LoginController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\MaintenanceController | pattern | mutable: passwordPolicyValidator |
| discard | TYPO3\CMS\Install\Controller\ServerResponseCheckController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\SettingsController | pattern |  |
| discard | TYPO3\CMS\Install\Controller\UpgradeController | pattern | mutable: coreUpdateService,coreVersionService,matchers |
| discard | TYPO3\CMS\Linkvalidator\Controller\LinkValidatorController | pattern | mutable: pageRecord,modTS,searchLevel,checkOpt,reportSelectedLinkType,lastEditedRecord,id,searchFields |
| discard | TYPO3\CMS\Lowlevel\Controller\ConfigurationController | pattern |  |
| discard | TYPO3\CMS\Lowlevel\Controller\QuerySearchController | pattern | mutable: MOD_MENU,MOD_SETTINGS,formName,moduleName,showFieldAndTableNames,table,enablePrefix,noDownloadB,tableArray,queryConfig,extFieldLists,fields,storeList,enableQueryParts,lang,fieldName,name,fieldList,comp_offsets,compSQL,iconFactory |
| discard | TYPO3\CMS\Lowlevel\Controller\RawSearchController | pattern | mutable: MOD_MENU,MOD_SETTINGS,iconFactory |
| discard | TYPO3\CMS\Opendocs\Backend\ToolbarItems\OpenDocumentToolbarItem | pattern | mutable: request |
| discard | TYPO3\CMS\Opendocs\Controller\OpenDocumentController | pattern |  |
| discard | TYPO3\CMS\Reactions\Controller\ManagementController | pattern |  |
| discard | TYPO3\CMS\Recycler\Controller\RecyclerAjaxController | pattern |  |
| discard | TYPO3\CMS\Recycler\Controller\RecyclerModuleController | pattern |  |
| discard | TYPO3\CMS\Redirects\Controller\ManagementController | pattern | mutable: uriBuilder,iconFactory,redirectRepository,moduleTemplateFactory,eventDispatcher,componentFactory,modulePaginationService |
| discard | TYPO3\CMS\Redirects\Controller\QrCodeModuleController | pattern | mutable: uriBuilder,iconFactory,redirectRepository,moduleTemplateFactory,componentFactory,modulePaginationService |
| discard | TYPO3\CMS\Redirects\Controller\RecordHistoryRollbackController | pattern |  |
| discard | TYPO3\CMS\Redirects\Controller\ShortUrlGeneratorController | pattern |  |
| discard | TYPO3\CMS\Redirects\Controller\ShortUrlModuleController | pattern |  |
| discard | TYPO3\CMS\Scheduler\Controller\NewSchedulerTaskController | pattern | mutable: returnUrl,defaultValues |
| discard | TYPO3\CMS\Scheduler\Controller\SchedulerModuleController | pattern | mutable: currentAction |
| discard | TYPO3\CMS\Tstemplate\Controller\ActiveTypoScriptController | pattern | mutable: iconFactory,uriBuilder,connectionPool,siteFinder,componentFactory,dataHandler,tcaSchemaFactory |
| discard | TYPO3\CMS\Tstemplate\Controller\ConstantEditorController | pattern | mutable: iconFactory,uriBuilder,connectionPool,siteFinder,componentFactory,dataHandler,tcaSchemaFactory |
| discard | TYPO3\CMS\Tstemplate\Controller\InfoModifyController | pattern | mutable: iconFactory,uriBuilder,connectionPool,siteFinder,componentFactory,dataHandler,tcaSchemaFactory |
| discard | TYPO3\CMS\Tstemplate\Controller\TemplateAnalyzerController | pattern | mutable: iconFactory,uriBuilder,connectionPool,siteFinder,componentFactory,dataHandler,tcaSchemaFactory |
| discard | TYPO3\CMS\Tstemplate\Controller\TemplateRecordsOverviewController | pattern | mutable: iconFactory,uriBuilder,connectionPool,siteFinder,componentFactory,dataHandler,tcaSchemaFactory |
| discard | TYPO3\CMS\Viewpage\Controller\ViewModuleController | pattern | mutable: pageContext |
| discard | TYPO3\CMS\Webhooks\Controller\ManagementController | pattern |  |
| discard | TYPO3\CMS\Workspaces\Controller\PreviewController | pattern |  |
| discard | TYPO3\CMS\Workspaces\Controller\ReviewController | pattern |  |
| discard | TYPO3\CMS\Workspaces\Controller\WorkspacesAjaxController | pattern |  |
| discard | TYPO3\CMS\Extensionmanager\Report\ExtensionComposerStatus | unloadable |  |
| keep | cache.assets | cache-frontend |  |
| keep | cache.dashboard.rss | cache-frontend |  |
| keep | cache.hash | cache-frontend |  |
| keep | cache.pages | cache-frontend |  |
| keep | cache.typoscript | cache-frontend | mutable: identifier,backend |
| keep | TYPO3\CMS\Backend\CodeEditor\Registry\AddonRegistry | pinned | mutable: registeredAddons |
| keep | TYPO3\CMS\Backend\CodeEditor\Registry\ModeRegistry | pinned | mutable: registeredModes,defaultMode |
| keep | TYPO3\CMS\Core\Cache\CacheManager | pinned | mutable: caches,cacheConfigurations,cacheGroups,defaultCacheConfiguration,disableCaching |
| keep | TYPO3\CMS\Core\Console\CommandRegistry_decorated_2 | pinned | mutable: commandConfigurations,aliases |
| keep | TYPO3\CMS\Core\Context\Context | pinned | mutable: aspects |
| keep | TYPO3\CMS\Core\Country\CountryProvider | pinned | mutable: rawData,countries |
| keep | TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry | pinned | mutable: pageTypes |
| keep | TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory | pinned | mutable: softReferenceParsers |
| keep | TYPO3\CMS\Core\Database\ConnectionPool_decorated_1 | pinned | mutable: customDoctrineTypes,overrideDoctrineTypes,defaultRestrictionContainer; static: connections |
| keep | TYPO3\CMS\Core\EventDispatcher\EventDispatcher | pinned |  |
| keep | TYPO3\CMS\Core\EventDispatcher\ListenerProvider_decorated_3 | pinned | mutable: container,listeners |
| keep | TYPO3\CMS\Core\Http\MiddlewareStackResolver | pinned | mutable: container,dependencyOrderingService,cache,baseCacheIdentifier |
| keep | TYPO3\CMS\Core\Imaging\IconRegistry_decorated_1 | pinned | mutable: fullInitialized,tcaInitialized,flagsInitialized,backendIconsInitialized,icons,backendIconDeclaration,staticIcons,fileExtensionMapping,mimeTypeMapping,iconAliases,deprecatedIcons,defaultIconIdentifier,cache,cacheIdentifier |
| keep | TYPO3\CMS\Core\Localization\Locales | pinned | mutable: languages,localeDependencies |
| keep | TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry | pinned | mutable: registry,instances,managers |
| keep | TYPO3\CMS\Core\Page\AssetCollector | pinned | mutable: javaScripts,inlineJavaScripts,javaScriptModules,styleSheets,inlineStyleSheets,media |
| keep | TYPO3\CMS\Core\Resource\Collection\FileCollectionRegistry | pinned | mutable: types |
| keep | TYPO3\CMS\Core\Resource\Driver\DriverRegistry | pinned | mutable: drivers,driverConfigurations |
| keep | TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry | pinned | mutable: registeredTaskTypes |
| keep | TYPO3\CMS\Core\Resource\Rendering\RendererRegistry | pinned | mutable: classNames,instances |
| keep | TYPO3\CMS\Core\Resource\TextExtraction\TextExtractorRegistry | pinned | mutable: textExtractorClasses,instances |
| keep | TYPO3\CMS\Core\Schema\TcaSchemaFactory | pinned | mutable: schemata |
| keep | TYPO3\CMS\Core\Site\Set\CategoryRegistry | pinned | mutable: setRegistry |
| keep | TYPO3\CMS\Core\Site\Set\SetRegistry | pinned | mutable: orderedSets,invalidSets,dependencyOrderingService,setCollector,logger |
| keep | TYPO3\CMS\Core\Site\Set\YamlSetDefinitionProvider | pinned | mutable: sets |
| keep | TYPO3\CMS\Extbase\Persistence\ClassesConfiguration | pinned | mutable: configuration |
| keep | TYPO3\CMS\Extbase\Reflection\ReflectionService | pinned | mutable: cacheIdentifier,dataCache,dataCacheNeedsUpdate,classSchemata |
| keep | TYPO3\CMS\Frontend\ContentObject\Menu\MenuContentObjectFactory | pinned | mutable: menuTypeToClassMapping |
| keep | TYPO3\CMS\Webhooks\WebhookTypesRegistry | pinned | mutable: webhookTypes |
| keep | cache.runtime | pinned |  |
| keep | TYPO3\CMS\Core\Package\Cache\PackageDependentCacheIdentifier | pinned-via:TYPO3\CMS\Core\Http\MiddlewareStackResolver | mutable: baseIdentifier,prefix,additionalIdentifier |
| keep | TYPO3\CMS\Core\Service\DependencyOrderingService | pinned-via:TYPO3\CMS\Core\Http\MiddlewareStackResolver |  |
| keep | Psr\EventDispatcher\EventDispatcherInterface_decorated_1 | pinned-via:TYPO3\CMS\Core\Schema\TcaSchemaFactory |  |
| keep | TYPO3\CMS\Adminpanel\Service\EventDispatcher | pinned-via:TYPO3\CMS\Core\Schema\TcaSchemaFactory | mutable: dispatchedEvents |
| keep | TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools | pinned-via:TYPO3\CMS\Core\Schema\TcaSchemaFactory |  |
| keep | TYPO3\CMS\Core\Schema\FieldTypeFactory | pinned-via:TYPO3\CMS\Core\Schema\TcaSchemaFactory | mutable: availableFieldTypes |
| keep | TYPO3\CMS\Core\Schema\TcaSchemaBuilder | pinned-via:TYPO3\CMS\Core\Schema\TcaSchemaFactory |  |
| keep | TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader | pinned-via:TYPO3\CMS\Core\Site\Set\YamlSetDefinitionProvider |  |
| keep | TYPO3\CMS\Core\Settings\SettingDefinitionValidation | pinned-via:TYPO3\CMS\Core\Site\Set\YamlSetDefinitionProvider |  |
| keep | TYPO3\CMS\Core\Settings\SettingsTypeRegistry | pinned-via:TYPO3\CMS\Core\Site\Set\YamlSetDefinitionProvider |  |
| keep | cache.extbase | pinned-via:TYPO3\CMS\Extbase\Persistence\ClassesConfiguration |  |
| keep | Ochorocho\FrankenPhp\EventListener\AddFrankenPhpModeToSystemInformation | readonly |  |
| keep | Ochorocho\FrankenPhp\Worker\WorkerStateResetter | readonly |  |
| keep | Psr\Http\Message\ResponseFactoryInterface | readonly |  |
| keep | Psr\Http\Message\ServerRequestFactoryInterface | readonly |  |
| keep | Psr\Http\Message\StreamFactoryInterface | readonly |  |
| keep | Psr\Http\Message\UploadedFileFactoryInterface | readonly |  |
| keep | Psr\Http\Message\UriFactoryInterface | readonly |  |
| keep | TYPO3\CMS\Adminpanel\Middleware\AdminPanelInitiator | readonly |  |
| keep | TYPO3\CMS\Adminpanel\Middleware\SqlLogging | readonly |  |
| keep | TYPO3\CMS\Adminpanel\Service\ModuleLoader | readonly |  |
| keep | TYPO3\CMS\Backend\Backend\Avatar\Avatar | readonly |  |
| keep | TYPO3\CMS\Backend\Backend\Bookmark\Security\BookmarkGroupVoter | readonly |  |
| keep | TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider | readonly |  |
| keep | TYPO3\CMS\Backend\Context\PageContextFactory | readonly |  |
| keep | TYPO3\CMS\Backend\EventListener\InitializeCodeEditorInEditFileForm | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataCompiler | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseLanguageRows | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabasePageLanguageOverlayRows | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUserPermissionCheck | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\InitializeProcessedTca | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\SiteResolving | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsRemoveEmptyRelations | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaFlexPrepare | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaInputPlaceholders | readonly |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\UserSettingsDatabaseEditRow | readonly |  |
| keep | TYPO3\CMS\Backend\Form\InlineStackProcessor | readonly |  |
| keep | TYPO3\CMS\Backend\Form\Processor\SelectItemProcessor | readonly |  |
| keep | TYPO3\CMS\Backend\History\RecordHistoryRollback | readonly |  |
| keep | TYPO3\CMS\Backend\Middleware\LockedBackendGuard | readonly |  |
| keep | TYPO3\CMS\Backend\Middleware\SiteResolver | readonly |  |
| keep | TYPO3\CMS\Backend\Module\ModuleFactory | readonly |  |
| keep | TYPO3\CMS\Backend\Preview\StandardPreviewRendererResolver | readonly |  |
| keep | TYPO3\CMS\Backend\Search\EventListener\AddLiveSearchFrontendUriResolverListener | readonly |  |
| keep | TYPO3\CMS\Backend\Search\EventListener\AddLiveSearchResultActionsListener | readonly |  |
| keep | TYPO3\CMS\Backend\Template\Components\Breadcrumb | readonly |  |
| keep | TYPO3\CMS\Backend\Tree\Repository\PageTreeFilter | readonly |  |
| keep | TYPO3\CMS\Backend\Upgrades\UserSettingsMigration | readonly |  |
| keep | TYPO3\CMS\Backend\Upgrades\UserSettingsNormalizationMigration | readonly |  |
| keep | TYPO3\CMS\Backend\Upgrades\UserSettingsScrubbingMigration | readonly |  |
| keep | TYPO3\CMS\Backend\View\BackendViewFactory | readonly |  |
| keep | TYPO3\CMS\Backend\View\ValueFormatter\FlexFormValueFormatter | readonly |  |
| keep | TYPO3\CMS\Belog\Domain\Repository\LogEntryRepository | readonly |  |
| keep | TYPO3\CMS\Core\Authentication\CommandLineUserCreation | readonly |  |
| keep | TYPO3\CMS\Core\Authentication\GroupResolver | readonly |  |
| keep | TYPO3\CMS\Core\Authentication\UserSettingsSchema | readonly |  |
| keep | TYPO3\CMS\Core\Charset\CharsetConverter | readonly |  |
| keep | TYPO3\CMS\Core\Charset\CharsetProvider | readonly |  |
| keep | TYPO3\CMS\Core\Configuration\Extension\ExtLocalconfFactory | readonly |  |
| keep | TYPO3\CMS\Core\Configuration\Extension\ExtTablesFactory | readonly |  |
| keep | TYPO3\CMS\Core\Configuration\Features | readonly |  |
| keep | TYPO3\CMS\Core\Configuration\Richtext | readonly |  |
| keep | TYPO3\CMS\Core\Configuration\SiteConfiguration | readonly |  |
| keep | TYPO3\CMS\Core\Configuration\SiteWriter | readonly |  |
| keep | TYPO3\CMS\Core\Configuration\Tca\TcaFactory | readonly |  |
| keep | TYPO3\CMS\Core\Core\ClassLoadingInformationUpdater | readonly |  |
| keep | TYPO3\CMS\Core\Crypto\HashService | readonly |  |
| keep | TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory | readonly |  |
| keep | TYPO3\CMS\Core\Crypto\Random | readonly |  |
| keep | TYPO3\CMS\Core\DataHandling\PagePermissionAssembler | readonly |  |
| keep | TYPO3\CMS\Core\Database\DriverMiddlewareService | readonly |  |
| keep | TYPO3\CMS\Core\DependencyInjection\EnvVarProcessor | readonly |  |
| keep | TYPO3\CMS\Core\Domain\Access\RecordAccessVoter | readonly |  |
| keep | TYPO3\CMS\Core\ExpressionLanguage\ProviderConfigurationLoader | readonly |  |
| keep | TYPO3\CMS\Core\FormProtection\FormProtectionFactory | readonly |  |
| keep | TYPO3\CMS\Core\Html\HtmlCropper | readonly |  |
| keep | TYPO3\CMS\Core\Html\SanitizerBuilderFactory | readonly |  |
| keep | TYPO3\CMS\Core\Http\Client\GuzzleClientFactory | readonly |  |
| keep | TYPO3\CMS\Core\Http\RequestFactory | readonly |  |
| keep | TYPO3\CMS\Core\Imaging\IconFactory | readonly |  |
| keep | TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService | readonly |  |
| keep | TYPO3\CMS\Core\Localization\CacheWarmer | readonly |  |
| keep | TYPO3\CMS\Core\Localization\LabelFileResolver | readonly |  |
| keep | TYPO3\CMS\Core\Localization\LanguageServiceFactory | readonly |  |
| keep | TYPO3\CMS\Core\Localization\LocalizationFactory | readonly |  |
| keep | TYPO3\CMS\Core\Localization\TcaSystemLanguageCollector | readonly |  |
| keep | TYPO3\CMS\Core\Localization\TranslationDomainMapper | readonly |  |
| keep | TYPO3\CMS\Core\Mail\TemplatedEmailFactory | readonly |  |
| keep | TYPO3\CMS\Core\Mail\TransportFactory | readonly |  |
| keep | TYPO3\CMS\Core\Messaging\Renderer\BootstrapRenderer | readonly |  |
| keep | TYPO3\CMS\Core\Middleware\NormalizedParamsAttribute | readonly |  |
| keep | TYPO3\CMS\Core\Middleware\ResponsePropagation | readonly |  |
| keep | TYPO3\CMS\Core\Package\Initialization\CheckForImportRequirements | readonly |  |
| keep | TYPO3\CMS\Core\PasswordPolicy\Generator\PasswordGenerator | readonly |  |
| keep | TYPO3\CMS\Core\PasswordPolicy\PasswordService | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Cache\FlushCacheTagForFile | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Cache\FlushCacheTagForFolder | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Cache\FlushCacheTagForMetaData | readonly |  |
| keep | TYPO3\CMS\Core\Resource\FileCollectionRepository | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Index\ExtractorRegistry | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Index\FileIndexRepository | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Index\MetaDataRepository | readonly |  |
| keep | TYPO3\CMS\Core\Resource\ProcessedFileRepository | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Processing\FileDeletionAspect | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Security\FileNameValidator | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Security\SvgSanitizer | readonly |  |
| keep | TYPO3\CMS\Core\Resource\Service\ExtractorService | readonly |  |
| keep | TYPO3\CMS\Core\Routing\RequestContextFactory | readonly |  |
| keep | TYPO3\CMS\Core\Routing\SiteMatcher | readonly |  |
| keep | TYPO3\CMS\Core\Routing\SiteUrlResolver | readonly |  |
| keep | TYPO3\CMS\Core\Schema\FlexFormSchemaFactory | readonly |  |
| keep | TYPO3\CMS\Core\Schema\SearchableSchemaFieldsCollector | readonly |  |
| keep | TYPO3\CMS\Core\Security\AllowedCallableAssertion | readonly |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\Configuration\CspConfigurationFactory | readonly |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\Middleware\ResponseService | readonly |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\ModelService | readonly |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\Reporting\ReportRepository | readonly |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\Reporting\ResolutionRepository | readonly |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\ScopeRepository | readonly |  |
| keep | TYPO3\CMS\Core\Serializer\AuthenticatedMessageDeserializer | readonly |  |
| keep | TYPO3\CMS\Core\Serializer\DenyListDeserializer | readonly |  |
| keep | TYPO3\CMS\Core\Serializer\DeserializationService | readonly |  |
| keep | TYPO3\CMS\Core\Service\DatabaseUpgradeWizardsService | readonly |  |
| keep | TYPO3\CMS\Core\Service\FlexFormService | readonly |  |
| keep | TYPO3\CMS\Core\Service\MarkerBasedTemplateService | readonly |  |
| keep | TYPO3\CMS\Core\Service\OpcodeCacheService | readonly |  |
| keep | TYPO3\CMS\Core\Settings\SettingsFactory | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\BoolType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\ColorType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\IntType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\NumberType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\PageType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\StringListType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\StringType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\TextType | readonly |  |
| keep | TYPO3\CMS\Core\Settings\Type\UrlType | readonly |  |
| keep | TYPO3\CMS\Core\Site\SiteFinder | readonly |  |
| keep | TYPO3\CMS\Core\Site\SiteSettingsFactory | readonly |  |
| keep | TYPO3\CMS\Core\Slug\SlugNormalizer | readonly |  |
| keep | TYPO3\CMS\Core\SysLog\Repository\LogEntryRepository | readonly |  |
| keep | TYPO3\CMS\Core\SystemResource\Identifier\SystemResourceIdentifierFactory_decorated_1 | readonly |  |
| keep | TYPO3\CMS\Core\SystemResource\Publishing\DefaultSystemResourcePublisher | readonly |  |
| keep | TYPO3\CMS\Core\Text\TextCropper | readonly |  |
| keep | TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository | readonly |  |
| keep | TYPO3\CMS\Core\TypoScript\TypoScriptService | readonly |  |
| keep | TYPO3\CMS\Core\TypoScript\TypoScriptStringFactory | readonly |  |
| keep | TYPO3\CMS\Core\Upgrades\BackendUserLanguageMigration | readonly |  |
| keep | TYPO3\CMS\Core\Upgrades\MigrateExtensionDataImportRegistryKeysUpdate | readonly |  |
| keep | TYPO3\CMS\Core\Upgrades\PagesRecyclerDoktypeMigration | readonly |  |
| keep | TYPO3\CMS\Core\Upgrades\SysFileMimeTypeMigration | readonly |  |
| keep | TYPO3\CMS\Core\Utility\DiffUtility | readonly |  |
| keep | TYPO3\CMS\Dashboard\Factory\WidgetSettingsFactory | readonly |  |
| keep | TYPO3\CMS\Extbase\EventListener\ExtbaseHistoryTracker | readonly |  |
| keep | TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapFactory | readonly |  |
| keep | TYPO3\CMS\Extbase\Service\ActionAuthorizationService | readonly |  |
| keep | TYPO3\CMS\Extbase\Service\ImageService | readonly |  |
| keep | TYPO3\CMS\Extensionmanager\Domain\Repository\ExtensionRepository | readonly |  |
| keep | TYPO3\CMS\Extensionmanager\EventListener\ExcludeExtensionTableFromReferenceIndexEventListener | readonly |  |
| keep | TYPO3\CMS\Extensionmanager\Initialization\DispatchAfterPackageActivationEventOnPackageInitialization | readonly |  |
| keep | TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory | readonly |  |
| keep | TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactory | readonly |  |
| keep | TYPO3\CMS\Fluid\EventListener\CacheWarmupEventListener | readonly |  |
| keep | TYPO3\CMS\Fluid\Service\CacheWarmupService | readonly |  |
| keep | TYPO3\CMS\Fluid\Service\TemplateFinder | readonly |  |
| keep | TYPO3\CMS\Form\Domain\Configuration\PersistenceConfigurationService | readonly |  |
| keep | TYPO3\CMS\Form\EventListener\TranslateDefaultValueBeforeRendering | readonly |  |
| keep | TYPO3\CMS\Form\Mvc\Configuration\TypoScriptService | readonly |  |
| keep | TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManager | readonly |  |
| keep | TYPO3\CMS\Form\Service\FormTransferService | readonly |  |
| keep | TYPO3\CMS\Form\Storage\StorageAdapterFactory | readonly |  |
| keep | TYPO3\CMS\FrontendLogin\Domain\Repository\FrontendUserRepository | readonly |  |
| keep | TYPO3\CMS\Frontend\ContentObject\ContentDataProcessor | readonly |  |
| keep | TYPO3\CMS\Frontend\DataProcessing\DatabaseQueryProcessor | readonly |  |
| keep | TYPO3\CMS\Frontend\DataProcessing\FlexFormProcessor | readonly |  |
| keep | TYPO3\CMS\Frontend\Middleware\ContentLengthResponseHeader | readonly |  |
| keep | TYPO3\CMS\Frontend\Middleware\PreviewSimulator | readonly |  |
| keep | TYPO3\CMS\Frontend\Page\FrontendUrlPrefix | readonly |  |
| keep | TYPO3\CMS\Frontend\Typolink\DatabaseRecordLinkBuilder | readonly |  |
| keep | TYPO3\CMS\Frontend\Typolink\LinkResultService | readonly |  |
| keep | TYPO3\CMS\Frontend\Typolink\LinkVarsCalculator | readonly |  |
| keep | TYPO3\CMS\Frontend\Upgrades\MediaFieldsZeroToNullUpdateWizard | readonly |  |
| keep | TYPO3\CMS\IndexedSearch\Hook\AvailableTcaTables | readonly |  |
| keep | TYPO3\CMS\Install\Authentication\AuthenticationService | readonly |  |
| keep | TYPO3\CMS\Install\Middleware\AssetPublishing | readonly |  |
| keep | TYPO3\CMS\Install\Middleware\Installer | readonly |  |
| keep | TYPO3\CMS\Install\Middleware\JavaScriptLanguageDomainProvider | readonly |  |
| keep | TYPO3\CMS\Install\Service\ClearCacheService | readonly |  |
| keep | TYPO3\CMS\Install\Service\ClearTableService | readonly |  |
| keep | TYPO3\CMS\Install\Service\LoadTcaService | readonly |  |
| keep | TYPO3\CMS\Install\Service\SetupService | readonly |  |
| keep | TYPO3\CMS\Install\Service\SilentTemplateFileUpgradeService | readonly |  |
| keep | TYPO3\CMS\Linkvalidator\EventListener\CheckBrokenRteLinkEventListener | readonly |  |
| keep | TYPO3\CMS\Linkvalidator\Upgrades\LinkValidatorFieldMigration | readonly |  |
| keep | TYPO3\CMS\Opendocs\Domain\Repository\OpenDocumentRepository | readonly |  |
| keep | TYPO3\CMS\Opendocs\EventListener\TrackOpenDocumentsEventListener | readonly |  |
| keep | TYPO3\CMS\Reactions\Http\Middleware\ReactionResolver | readonly |  |
| keep | TYPO3\CMS\Reactions\ReactionRegistry | readonly |  |
| keep | TYPO3\CMS\Redirects\Data\SourceHostProvider | readonly |  |
| keep | TYPO3\CMS\Redirects\EventListener\AddPageTypeZeroSource | readonly |  |
| keep | TYPO3\CMS\Redirects\EventListener\AddPlainSlugReplacementSource | readonly |  |
| keep | TYPO3\CMS\Redirects\EventListener\AddUrlsForSubPagesForIntegrityCheck | readonly |  |
| keep | TYPO3\CMS\Redirects\EventListener\IncrementHitCount | readonly |  |
| keep | TYPO3\CMS\Redirects\FormDataProvider\ValuePickerItemDataProvider | readonly |  |
| keep | TYPO3\CMS\Redirects\Service\ShortUrlService | readonly |  |
| keep | TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository | readonly |  |
| keep | TYPO3\CMS\Scheduler\EventListener\AddSchedulableCommandsAsNativeTaskTypes | readonly |  |
| keep | TYPO3\CMS\Scheduler\Service\TaskService | readonly |  |
| keep | TYPO3\CMS\Scheduler\Task\TaskSerializer | readonly |  |
| keep | TYPO3\CMS\Seo\MetaTag\MetaTagGenerator | readonly |  |
| keep | TYPO3\CMS\SysNote\Domain\Repository\SysNoteRepository | readonly |  |
| keep | TYPO3\CMS\Webhooks\Tca\ItemsProcFunc\WebhookTypesItemsProcFunc | readonly |  |
| keep | TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository | readonly |  |
| keep | TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository | readonly |  |
| keep | TYPO3\CMS\Workspaces\EventListener\PageTreeItemsHighlighter | readonly |  |
| keep | TYPO3\CMS\Workspaces\EventListener\WorkspaceDependencyReferenceListener | readonly |  |
| keep | TYPO3\CMS\Workspaces\Hook\DataHandlerInternalWorkspaceTablesHook | readonly |  |
| keep | TYPO3\CMS\Workspaces\Service\StagesService | readonly |  |
| keep | bookmarksWidgetConfiguration | readonly |  |
| keep | dashboard.widget.latestBeLogins | readonly |  |
| keep | dashboard.widget.latestChangedPages | readonly |  |
| keep | dashboard.widget.pages_width_internal_note | readonly |  |
| keep | dashboard.widget.recentDocuments | readonly |  |
| keep | dashboard.widget.rss | readonly |  |
| keep | dashboard.widget.t3news | readonly |  |
| keep | dashboard.widget.t3securityAdvisories | readonly |  |
| keep | docGettingStartedWidgetConfiguration | readonly |  |
| keep | docTSconfigWidgetConfiguration | readonly |  |
| keep | docTypoScriptReferenceWidgetConfiguration | readonly |  |
| keep | extension-configuration | readonly |  |
| keep | failedLoginsWidgetConfiguration | readonly |  |
| keep | frankenphp-prometheus-metricsWidgetConfiguration | readonly |  |
| keep | latestBeLoginsWidgetConfiguration | readonly |  |
| keep | latestChangedPagesWidgetConfiguration | readonly |  |
| keep | pages_width_internal_noteWidgetConfiguration | readonly |  |
| keep | recentDocumentsWidgetConfiguration | readonly |  |
| keep | rssWidgetConfiguration | readonly |  |
| keep | seo-pagesWithoutMetaDescriptionWidgetConfiguration | readonly |  |
| keep | sysLogErrorsWidgetConfiguration | readonly |  |
| keep | t3informationWidgetConfiguration | readonly |  |
| keep | t3newsWidgetConfiguration | readonly |  |
| keep | t3securityAdvisoriesWidgetConfiguration | readonly |  |
| keep | typeOfUsersWidgetConfiguration | readonly |  |
| keep | .lazy.TYPO3\CMS\Form\Service\TranslationService | readonly-props |  |
| keep | .lazy.backend.routes.Ys91S5F | readonly-props |  |
| keep | Doctrine\Instantiator\InstantiatorInterface | readonly-props | static: cachedInstantiators,cachedCloneables |
| keep | Ochorocho\FrankenPhp\Middleware\PreserveNativeSessionCookies | readonly-props |  |
| keep | TYPO3\CMS\Adminpanel\Service\ConfigurationService | readonly-props |  |
| keep | TYPO3\CMS\Backend\Authentication\BackendLocker | readonly-props |  |
| keep | TYPO3\CMS\Backend\Configuration\SiteTcaConfiguration | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseEffectivePid | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabasePageRootline | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRecordOverrideValues | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRecordTypeValue | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDateTimeFields | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDefaultAsReadonly | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDefaultValues | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowInitializeNew | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUniqueUidNewRow | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\EvaluateDisplayConditions | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\PageTsConfig | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\PageTsConfigMerged | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsOverrides | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessCommon | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessFieldDescriptions | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessFieldLabels | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessPlaceholders | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessRecordTitle | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsProcessShowitem | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsRemoveUnused | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaFlexProcess | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaGroup | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaInlineConfiguration | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaInlineExpandCollapseState | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaInlineIsOnSymmetricSide | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaRecordTitle | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaSlug | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\TcaUuid | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormDataProvider\UserTsConfig | readonly-props |  |
| keep | TYPO3\CMS\Backend\Form\FormResultFactory | readonly-props |  |
| keep | TYPO3\CMS\Backend\Middleware\AdditionalResponseHeaders | readonly-props |  |
| keep | TYPO3\CMS\Backend\Resource\PublicUrlPrefixer | readonly-props | static: isProcessingUrl |
| keep | TYPO3\CMS\Backend\Search\EventListener\ExcludePagesFromSearchFieldsLookup | readonly-props |  |
| keep | TYPO3\CMS\Backend\Security\SudoMode\Access\AccessFactory | readonly-props |  |
| keep | TYPO3\CMS\Core\Cache\DatabaseSchemaService | readonly-props |  |
| keep | TYPO3\CMS\Core\Information\Typo3Version | readonly-props |  |
| keep | TYPO3\CMS\Core\LinkHandling\EmailLinkHandler | readonly-props |  |
| keep | TYPO3\CMS\Core\Resource\MetaDataEventListener | readonly-props |  |
| keep | TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry | readonly-props |  |
| keep | TYPO3\CMS\Core\Resource\OnlineMedia\Metadata\Extractor | readonly-props |  |
| keep | TYPO3\CMS\Core\Resource\Security\StoragePermissionsAspect | readonly-props |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\Processing\AssetHandler | readonly-props |  |
| keep | TYPO3\CMS\Core\Security\ContentSecurityPolicy\Processing\GoogleMapsHandler | readonly-props | static: suggestion,policyNarrative |
| keep | TYPO3\CMS\Core\TypoScript\AST\Traverser\AstTraverser | readonly-props |  |
| keep | TYPO3\CMS\Core\TypoScript\IncludeTree\Traverser\IncludeTreeTraverser | readonly-props |  |
| keep | TYPO3\CMS\Extbase\EventListener\AddDefaultModuleIcon | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Persistence\Generic\Qom\QueryObjectModelFactory | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\PropertyMappingConfigurationBuilder | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\ArrayConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\BooleanConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\CoreTypeConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\DateTimeConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\EnumConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\FloatConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\IntegerConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\ObjectStorageConverter | readonly-props |  |
| keep | TYPO3\CMS\Extbase\Property\TypeConverter\StringConverter | readonly-props |  |
| keep | TYPO3\CMS\Extensionmanager\Utility\EmConfUtility | readonly-props |  |
| keep | TYPO3\CMS\Form\EventListener\FormatDateRenderableBeforeRendering | readonly-props |  |
| keep | TYPO3\CMS\Form\EventListener\ProcessFileListActionsEventListener | readonly-props |  |
| keep | TYPO3\CMS\Form\EventListener\ValidateAdvancedPasswordRenderable | readonly-props |  |
| keep | TYPO3\CMS\Form\Mvc\Property\PropertyMappingConfiguration | readonly-props |  |
| keep | TYPO3\CMS\Form\Service\DatabaseService | readonly-props |  |
| keep | TYPO3\CMS\Form\Service\FormDefinitionMigrationService | readonly-props |  |
| keep | TYPO3\CMS\Form\Service\TranslationService | readonly-props |  |
| keep | TYPO3\CMS\FrontendLogin\Event\ProcessRequestTokenListener | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Aspect\FileMetadataOverlayAspect | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Cache\CacheLifetimeCalculator | readonly-props |  |
| keep | TYPO3\CMS\Frontend\DataProcessing\CommaSeparatedValueProcessor | readonly-props |  |
| keep | TYPO3\CMS\Frontend\DataProcessing\FilesProcessor | readonly-props |  |
| keep | TYPO3\CMS\Frontend\DataProcessing\SiteLanguageProcessor | readonly-props |  |
| keep | TYPO3\CMS\Frontend\DataProcessing\SiteProcessor | readonly-props |  |
| keep | TYPO3\CMS\Frontend\DataProcessing\SplitProcessor | readonly-props |  |
| keep | TYPO3\CMS\Frontend\EventListener\AvoidContentSecurityPolicyNonceEventListener | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Middleware\CacheTimeout | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Middleware\MaintenanceMode | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Middleware\PageResolver | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Middleware\SiteBaseRedirectResolver | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Resource\PublicUrlPrefixer | readonly-props | static: isProcessingUrl |
| keep | TYPO3\CMS\Frontend\Typolink\TelephoneLinkBuilder | readonly-props |  |
| keep | TYPO3\CMS\Frontend\Upgrades\SynchronizeColPosAndCTypeWithDefaultLanguage | readonly-props |  |
| keep | TYPO3\CMS\IndexedSearch\Service\DatabaseSchemaService | readonly-props |  |
| keep | TYPO3\CMS\IndexedSearch\Upgrades\IndexedSearchCTypeMigration | readonly-props |  |
| keep | TYPO3\CMS\Install\Factory\ImportMapFactory | readonly-props |  |
| keep | TYPO3\CMS\Install\Http\NotFoundRequestHandler | readonly-props |  |
| keep | TYPO3\CMS\Linkvalidator\Repository\BrokenLinkRepository | readonly-props |  |
| keep | TYPO3\CMS\Linkvalidator\Repository\PagesRepository | readonly-props |  |
| keep | TYPO3\CMS\Opendocs\Backend\OpenDocumentUpdateSignal | readonly-props |  |
| keep | TYPO3\CMS\Reactions\Form\ReactionItemsProcFunc | readonly-props |  |
| keep | TYPO3\CMS\Reactions\Reaction\CreateRecordReaction | readonly-props |  |
| keep | TYPO3\CMS\Reactions\Repository\ReactionRepository | readonly-props |  |
| keep | TYPO3\CMS\Redirects\EventListener\RecordHistoryRollbackEventsListener | readonly-props |  |
| keep | TYPO3\CMS\Redirects\FormDataProvider\QrCodeSourceHostDataProvider | readonly-props |  |
| keep | TYPO3\CMS\Redirects\FormDataProvider\ShortUrlDataProvider | readonly-props |  |
| keep | TYPO3\CMS\Redirects\Hooks\HandleNewQrCodeRecord | readonly-props |  |
| keep | TYPO3\CMS\Redirects\Service\TemporaryPermissionMutationService | readonly-props |  |
| keep | TYPO3\CMS\Scheduler\Migration\SchedulerDatabaseStorageMigration | readonly-props |  |
| keep | TYPO3\CMS\SysNote\Migration\SysNoteDashboardWidgetDatabaseMigration | readonly-props |  |
| keep | TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate | readonly-props |  |
| keep | TYPO3\CMS\Workspaces\Middleware\WorkspacePreviewPermissions | readonly-props |  |
| keep | backend.middlewares | readonly-props |  |
| keep | backend.modules.warmer | readonly-props |  |
| keep | backend.modules_decorated_34 | readonly-props |  |
| keep | backend.routes.warmer | readonly-props |  |
| keep | backend.routes_decorated_34 | readonly-props |  |
| keep | core.middlewares | readonly-props |  |
| keep | dashboard.configuration.warmer | readonly-props |  |
| keep | dashboard.presets_decorated_1 | readonly-props |  |
| keep | dashboard.widgetGroups_decorated_1 | readonly-props |  |
| keep | dashboard.widgets_decorated_1 | readonly-props |  |
| keep | fluid.component.collections_decorated_33 | readonly-props |  |
| keep | fluid.namespaces_decorated_33 | readonly-props |  |
| keep | frontend.middlewares | readonly-props |  |
| keep | icons_decorated_34 | readonly-props |  |
| keep | middlewares_decorated_33 | readonly-props |  |
