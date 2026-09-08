# Performance issues: what a TYPO3 request still costs in worker mode

Status: 2026-09-08. Measured on the `Build/` sandbox with the request profiler
(`FRANKENPHP_PROFILE=1`, `frankenphp:profile`, see `Documentation/Profiling.md`):
TYPO3 14.3.4, FrankenPHP 1.12.6 (PHP 8.5.9 ZTS, Homebrew), SQLite, file caches, 10 workers,
the Camino demo site, Apple Silicon. 24 warm requests per URL after 3 warm-ups, medians.
Full tables and the raw per-middleware breakdowns: `Documentation/Performance.md` round 8.

The extension's own work is no longer on the list. After the seven rounds recorded in
`Documentation/Performance.md`, a cached frontend page is 3.7 ms single-client, and the worker
reset costs 0.03 ms of it. What is left is TYPO3 Core work per request plus deployment choices.
The page is CPU-bound: 10 queries cost 0.13 ms in total, and half the request is a single call.

## Ranked bottlenecks

| # | Bottleneck | Measured | Solution | Owner |
| --- | --- | --- | --- | --- |
| 1 | Page TSconfig is built on every frontend request to resolve the backend layout (`PageLayoutResolver::getLayoutForPage()` → `PageTsConfigFactory::create()`), on cache hits too | ~2 ms of a 4 ms page, 49 %, zero database time | Core: cache the compiled `PageTsConfig` keyed by include-tree hash and condition verdicts, the way `FrontendTypoScriptFactory` already caches setup; or resolve the layout without the full TSconfig | Core, partly mitigated (#3) |
| 2 | 22 middlewares run for a page that is already in the cache | 3.9 ms of middleware own time | Serve cached anonymous pages as static files from the web server, PHP never runs | Deployment, not shipped |
| 3 | `unserialize()` of identical `typoscript` cache entries on every request | 4 frontend entries ~0.5 ms, page TSconfig include tree ~1.4 ms (15 files, 358 KB) | Shipped: `Classes/Cache/WorkerPhpFrontend` memoises `require()` results per worker, validated by file mtime | Extension, done |
| 4 | `response-propagation` runs twice, once in the core stack and once in the application stack | 0.28 ms frontend, 1.4 ms backend on a 290 KB body | Core: propagate once, or stream large bodies | Core |
| 5 | `sys_template` rows are queried on every request including page-cache hits | 0.21 ms, 1 query | Core's own TODO: join it into the rootline CTE | Core |
| 6 | The redirect list is read from the pages cache on every request | 0.21 ms | Core / EXT:redirects: memoise per request in the runtime cache | Core |
| 7 | Backend modules issue 119 to 160 queries per render | 1.6 to 1.8 ms on in-process SQLite, 30 to 50 ms over a MariaDB socket | Core: fewer per-record lookups in the layout renderer and the record list. Deployment: keep the database on the same host | Core, deployment |
| 8 | Worker state reset | 0.03 ms visible per request, 0.18 ms after the response | Done in round 4: the structural reset runs after `frankenphp_finish_request()` | Extension, done |

Row 2 was measured before it was rejected. With EXT:staticfilecache and a Caddy `file` matcher on
its cache directory a cached page answered in 0.38 ms average at 300 req/s and the sandbox reached
7106 req/s at saturation, with the bypass rules for query strings, backend cookies and
frontend-user cookies verified. The approach is not wanted in this project and nothing of it ships.

## Where the 4 ms of a cached page go

| Span | Own ms | Share | Queries |
| --- | --- | --- | --- |
| PageInformation: language, content_from_pid, backend layout, access | 4.11 | 49 % | 0 |
| `page-resolver` middleware | 0.51 | 6 % | 1 |
| Page cache read and setup-config load | 0.32 | 4 % | 0 |
| `response-propagation` middleware, two calls | 0.28 | 3 % | 0 |
| `site` middleware | 0.26 | 3 % | 0 |
| PageInformation: page record and rootline | 0.24 | 3 % | 2 |
| Settings and setup conditions | 0.23 | 3 % | 0 |
| `redirecthandler` middleware | 0.21 | 3 % | 0 |
| PageInformation: `sys_template` rows | 0.21 | 3 % | 1 |
| Worker reset before the request | 0.03 | 0.3 % | 0 |

Numbers are from a profiled run on a busy machine, so they sit 10 to 30 % above the quiet-machine
gate figures. The shares are the result, not the absolute values.

Inside the dominant block, temporary marks in Core showed that `settingLanguage()`,
`setContentFromPid()` and `checkBackendUserAccess()` cost single microseconds. Everything else is
`setPageLayout()`: rootline and data-provider preparation ~0.9 ms, include tree load ~1.4 ms,
condition matching and AST build ~1.3 ms, `toArray()` and layout resolution ~1.0 ms. The same work
runs under PHP-FPM on every request. Only the worker, having removed everything else, makes it
visible as the largest remaining item.

## What shipped against these issues

`WorkerPhpFrontend` replaces Core's `PhpFrontend` for the `typoscript` cache when the SAPI is
FrankenPHP. Core stores TypoScript and TSconfig trees as `return unserialize('…')` files. OPcache
keeps the compiled file, but the `unserialize()` ran per request. The memo keeps the unserialized
value and validates it against the file's mtime, so a cache flush in any process is seen on the
next request. Entries are content-addressed and bounded to 64.

| | Before | After |
| --- | --- | --- |
| Median total | 4.05 ms | 3.66 ms |
| Backend-layout block | 1.76 ms | 1.20 ms |
| Middleware stack own time | 3.58 ms | 3.20 ms |

The remaining 1.2 ms of that block is condition matching, AST building and `toArray()`. Only Core
can cache those, which is bottleneck 1.

## Further findings, not ranked

- **Content Security Policy.** `PolicyProvider::provideFor()` recomputes the mutated policy on
  every request. Core's own TODO asks for a policy cache per scope. `dropNonceFromHtmlResponse()`
  is a full pass over the response body. This only costs anything on sites that enable CSP. The
  Camino demo site does not, so it is absent from the table above.
- **Backend shell.** `/typo3/main` spends 17.7 ms in the controller. Six `addInlineLanguageLabelFile()`
  calls load label sets Core's own comment calls "a ton of labels", every toolbar item runs
  `checkAccess()`, `ModuleProvider` filters the module tree, and the import map is recomposed per
  request in Development because of the `EXEC_TIME` cache-bust suffix.
- **Cache headers.** TYPO3 sends `cache-control: private, no-store`, which prevents any shared or
  edge cache from storing a page. `config.sendCacheHeadersForSharedCaches = force` (TYPO3 13.3+)
  sends `s-maxage` instead. It is a per-site decision, not a default.
- **Page lock.** TYPO3 14 checks the page cache before it takes the page lock, so a warm cache hit
  acquires no lock at all. Earlier notes claiming otherwise were wrong.
- **Runtime cache.** `cache.runtime` is documented as per-script but Core never flushes it. The
  extension flushes it per request and keeps only entries proven request-independent. See the
  inventory table in `Documentation/WorkerMode.md`.

## Deployment items

1. A database server on a Unix socket for sites with heavy queries. On the small sandbox
   in-process SQLite beats MariaDB by 3 ms per cached page. Redis for the hot caches, not
   `ApcuBackend`, which loses to `FileBackend` on a threaded worker pool (round 7).
2. Threads about two per core, `MAX_REQUESTS` at 10000 or above, `MAX_MEMORY_MB` as a leak guard,
   a supervisor with `Restart=always`.
3. `TYPO3_CONTEXT=Production`, `FE.debug=0`, no admin panel. The admin panel alone costs 0.9 ms
   per page.
4. Measure at a fixed request rate, not in a closed loop. A closed-loop average only reports how
   many clients share the CPU.

## Still to do

Report the Core findings upstream: the page TSconfig cache (1), the `sys_template` join (5), the
redirect memo (6), `response-propagation` running twice (4), the backend query counts (7) and the
CSP policy cache. The full list with file references is in `Documentation/WorkerMode.md` under
"Findings worth fixing in Core".
