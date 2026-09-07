# Performance: what a request costs and how close 10 ms is

Two review rounds on 5 September 2026 against the `Build/` sandbox: TYPO3 14.3, FrankenPHP 1.12.6
(PHP 8.5.9 ZTS, Homebrew), SQLite database, the Camino demo site, Apple Silicon with 10 cores
shared with the load generator. "Server-side" is Caddy's per-request `duration`, "in PHP" is the
time inside `Application::run()` from a temporary probe. Run-to-run variance on this laptop is
about 20 percent; numbers are single runs unless noted.

## Where a cached frontend page spends its time

One client, before any change: 9.3 ms end to end (curl, fresh TLS handshake each time),
7.9 ms server-side, 7.4 ms in PHP, TYPO3 parse time 0 ms.

| Step | Cost | Owner |
| --- | --- | --- |
| Worker reset (`WorkerStateResetter::reset()`) | 0.2 ms, since round 4 only 16 µs inside the request (the rest runs after the response) | this extension |
| Rebuilding the discarded services (72 objects, mostly middlewares and TypoScript builders) | 0.45 ms, plus 0.9 ms for the dev-only admin panel renderer | this extension's model, Core constructors |
| `PrepareTypoScriptFrontendRendering` | 2.8 ms with SQLite caches, 2.3 ms with file caches | Core, cache backend, `sys_template` and rootline queries |
| `RedirectHandler` (redirect list from the pages cache) | 1.2 ms with SQLite caches, 0.13 ms with file caches | EXT:redirects, cache backend |
| `PageResolver`, `SiteResolver`, `FrontendUserAuthenticator`, CSP, headers | 1.5 ms | Core |
| Caddy, TLS, response transfer (74 KB) | 1.5 to 2 ms | Caddy |
| `gc_collect_cycles()` per request | under 1 µs | measured, unchanged |

The extension's own share is under 1 ms. Eight database queries remain per cached page
(`pages` for the resolver and rootline, `sys_template`).

## Round 1: 100 users with 1 s think time (about 95 requests per second)

| Configuration | k6 avg | k6 median | server-side p50 | in PHP p50 |
| --- | --- | --- | --- | --- |
| 2 threads, reconnect per request, SQLite caches (original) | 38 ms | 35 ms | 35 ms | 7.2 ms |
| 8 threads, reconnect per request | 44 ms | 44 ms | 44 ms | 33 ms |
| 2 threads, connections kept | 29 ms | 27 ms | 27 ms | |
| 8 threads, connections kept | 19 ms | 13 ms | 11 ms | 14 ms |
| 2 threads, kept, file caches | 24 ms | 23 ms | 23 ms | 4.3 ms alone |
| 10 threads, kept, file caches | 19 ms | 15 ms | 13 ms | 11 ms |

Findings behind the table:
- With a reconnect per request more threads made things worse: SQLite lock churn (open, schema
  parse, fcntl locks) inflated the SQLite-reading middlewares 5 to 17 times. Keeping connections
  fixed that (`ConnectionRecycler`).
- Moving `pages`, `hash`, `rootline` and `pagesection` to `FileBackend` removes 4 ms of cache reads
  per page and most of the remaining lock contention; the sandbox bootstrap now does this.
- The wait at 2 threads is queueing in front of PHP: 100 synchronised users arrive in bursts.
- `MAX_REQUESTS`, Caddy `debug`, opcache timestamp validation and the access log: no measurable
  effect on latency. SQLite WAL mode: about 7 percent more throughput, not adopted.
- Removing the admin panel saves 1 ms in PHP per request; it is a dev-only extension.

## Round 2: 20 users in a closed loop, no think time (the current `frontend-load.js`)

Without think time the average is arithmetic: average latency = concurrency divided by
throughput. The machine's throughput for this page is 750 to 850 per second (`ab`, keep-alive),
at which point FrankenPHP uses 850 to 890 percent CPU: the laptop is saturated. Each page costs
about 10 ms of CPU under all-core load against 4.3 ms alone (efficiency cores, frequency, memory
contention).

| Client | Result |
| --- | --- |
| `ab -c 8` | 10.6 to 12.8 ms mean, 630 to 755 req/s |
| `ab -c 20` | 24 to 25 ms mean, 800 req/s |
| k6, 8 users, this scenario | avg 10.6 ms, median 10.2 ms, 361 req/s |
| k6, 20 users, this scenario | avg 25 to 31 ms, median 23 to 28 ms, 315 to 358 req/s |

k6 reaches less throughput than `ab` because its four body checks on 74 KB pages cost client CPU
on the same machine. Nothing tested moves the ceiling: 20 or 40 threads, SQLite WAL, MariaDB in
Docker Desktop (slower: 8 queries per page through the VM network, 17 ms alone), opcache
validation off, access log off.

So on this laptop, "10 ms average" holds up to about 8 concurrent closed-loop clients, or at any
request rate the 10 cores can sustain with headroom. Twenty closed-loop clients need about
2000 pages per second, which is roughly 2.5 times this machine.

## Round 3: latency at a fixed request rate (`frontend-latency.js`)

The closed loop answers "how many clients share the CPU"; a `constant-arrival-rate` scenario
answers "what does a page cost at N requests per second". With the admin panel removed from the
sandbox (0.9 ms per page, dev-only) and 10 threads:

| Rate | k6 avg | median | p95 | dropped iterations |
| --- | --- | --- | --- | --- |
| 200 req/s | 5.7 ms | 5.1 ms | 6.1 ms | 0 after the first second |
| 300 req/s | 4.6 ms | 4.2 ms | 6.1 ms | 0 |
| 400 req/s | 159 ms | 69 ms | 616 ms | 1262: k6 itself saturates the machine |

That is the 10 ms target with room to spare, up to the point where the load generator on the
same laptop runs out of CPU. In the same session the closed-loop scenario averaged 24 ms and
`ab -c 8` 9.8 ms, unchanged physics.

## Round 4: the reset moves behind the response

FrankenPHP's `frankenphp_finish_request()` hands the response to the client and lets the worker
keep running. Since round 4 the structural part of the reset (discarding services, rotating the
`RequestId`, flushing `cache.runtime`, re-seeding `PageRenderer`, resolving the `Application` for
the next request) runs there, after response N; only the clock-bound part (connection recycling,
`EXEC_TIME`, `WorkerRequestStartingEvent`) still runs in front of request N+1. Same sandbox as
round 3 (10 threads, file caches, no admin panel), PHP 8.5.10:

| | before | after |
| --- | --- | --- |
| `X-FrankenPHP-Reset-Us` (reset inside the request) | 241 µs | 16 µs |
| `X-FrankenPHP-Post-Reset-Us` (reset after the previous response) | - | 155 µs |
| single client, `curl` with TLS handshake | 5.8 ms | 5.1 ms |
| `frontend-latency.js` at 300 req/s: avg | 4.6 ms | 3.8 ms |
| `frontend-latency.js` at 300 req/s: median | 4.2 ms | 3.6 ms |
| `frontend-latency.js` at 300 req/s: p95 | 6.1 ms | 4.5 ms |

Throughput is unchanged by construction: the same work runs on the same thread, it just no
longer sits between the request and its response. Backend reference for the same build,
`backend-load.js` (5 users, `/typo3/main`, Web>Layout and the record list for page 1, 10 threads):
100 % checks, median 34 ms, p95 141 ms. Not comparable with the 2026-09-04 backend numbers: until
today the scenario requested the list module under a route that no longer exists
(`/module/records`, now `/module/content/records`), so one of its three requests was a cheap
redirect to the login page. The first request a thread serves after boot
or a recycle still resets inline (`X-FrankenPHP-Reset-Mode: inline`); in the 60 s run above
that was 2 of 18 001 requests.

## What changed

- **The structural reset runs after the response** (`WorkerRequestCycle`, round 4).
- **Assets are served by Caddy, pages never stat the filesystem.** The generated Caddyfile serves
  `_assets/`, `fileadmin/`, `typo3temp/assets/`, `favicon.ico` and `robots.txt` through `file_server`
  with `precompressed zstd br gzip` and the cache lifetimes of TYPO3's `.htaccess` (one year for CSS
  and JS, one month otherwise); every other request is rewritten to the worker script directly instead
  of going through `try_files`, which stat-ed the request path first.
- **Database connections survive requests** (`ConnectionRecycler`, closed after an open
  transaction or 60 s idle).
- **Sandbox caches on `FileBackend`** (`scripts/setup-typo3.sh`); production equivalents are
  Redis or APCu. The sandbox no longer installs `typo3/cms-adminpanel`: no test used it and its
  renderer was rebuilt on every request.
- **`frontend-latency.js`**: the rate-based scenario with the 10 ms average as its threshold.
- **`frankenphp:init` defaults**: development one worker thread per core, production two per
  core, `MAX_REQUESTS=10000` for both. A recycle every 500 requests at 800 per second is 16 worker
  restarts per second and reproducibly aborted FrankenPHP 1.12.6.
- **The Caddyfile now carries the chosen values as placeholder defaults.** Caddy reads `.env`
  only with `--envfile`; the README's earlier `-e .env` was silently ignored (Caddy tolerates
  unknown flags), so ports and worker counts in `.env` never reached the Caddyfile. PHP still saw
  `.env` through `helhum/dotenv-connector`, which hid the mistake. `frankenphp:init` now prints
  the correct command.

## Round 6: full audit of the finished build (2026-09-06)

Everything below is one session on the same machine (Apple M4, 10 cores, 32 GB, load generator
on the same machine), FrankenPHP 1.12.6 / PHP 8.5.9 ZTS, TYPO3 14.3.4, Development context, SQLite,
`pages` / `hash` / `rootline` on `FileBackend`, 10 workers / 12 threads, `MAX_REQUESTS=10000`,
fresh worker pool at the start. Server-side numbers are curl or k6 `http_req_duration` unless noted.

### One client

| Request | keep-alive, 50 requests: mean / p50 / p90 | Notes |
| --- | --- | --- |
| `/camino/` (start page, 76.5 KB, 16.7 KB zstd) | 4.3 / 3 / 5 ms | p99 43 ms is the first request of a cold thread |
| `/camino/faqs` | 3.2 / 3 / 4 ms | |
| `/camino/packing-list` | 3.4 / 3 / 4 ms | |
| `/typo3/login` (backend login page) | 9.0 / 9 / 10 ms | |
| `/?__typo3_install` (per-request `index.php`, failsafe boot) | 10.4 / 10 / 11 ms | the only per-request PHP path |
| `_assets/.../dashboard.css` (Caddy `file_server`) | 0.21 / 0 / 0 ms | never reaches PHP |
| `/camino/` with a fresh TLS handshake per request | median 5.4 ms | the handshake is 2 ms of it |

Reset headers on a warm request: `X-FrankenPHP-Reset-Us: 20`, `-Post-Reset-Us: 184`,
`-Discarded: 67`, `-Reset-Mode: post`. First request of a freshly booted pool: 88 ms.

Authenticated backend, keep-alive, 30 requests each (admin session, `Referer` set: without it
TYPO3's referrer guard answers a 432-byte reload page instead of the module):

| Request | mean / p50 / p90 / p99 | HTML |
| --- | --- | --- |
| `/typo3/main` (backend shell) | 20.0 / 19 / 20 / 46 ms | 290 KB |
| Web > Layout, page 1 | 39.0 / 38 / 40 / 50 ms | 283 KB |
| Record list (`content/records`), page 1 | 37.1 / 37 / 38 / 41 ms | 387 KB |

### Fixed request rate (`frontend-latency.js`, 60 s each, 6 cached pages)

| Rate | avg | median | p90 | p95 | max | dropped | checks |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 100 req/s | 3.68 ms | 3.61 ms | 4.12 ms | 4.33 ms | 15.6 ms | 0 | 100 % |
| 200 req/s | 3.58 ms | 3.51 ms | 3.99 ms | 4.19 ms | 11.1 ms | 0 | 100 % |
| 300 req/s | 4.60 ms | 4.51 ms | 5.31 ms | 5.60 ms | 15.0 ms | 0 | 100 % |
| 400 req/s | 108 ms | 34 ms | 342 ms | 480 ms | 3.17 s | 726 | k6 saturates the machine |

Between 3.6 and 4.6 ms up to 300 req/s; the 400 req/s row describes the load generator, not the
server (see round 3). Run-to-run variation on the 300 req/s row within this day: 3.8 to 4.6 ms.

### Throughput ceiling and closed loops

| Scenario | Result |
| --- | --- |
| `ab -k -c 8 -n 6000 /camino/` | **1010 req/s**, mean 7.9 ms, p50 8 ms, p95 11 ms, 0 failed |
| `ab -k -c 20 -n 6000 /camino/` | 1010 req/s, mean 19.8 ms, p50 19 ms, p95 25 ms, 0 failed |
| `frontend-load.js`, 20 users, no think time, 2 min | 387 req/s, avg 22.5 ms, median 19.3 ms, p95 45.6 ms, 100 % checks |

The cached page costs about 1 ms of CPU per core at saturation (1010 req/s on 10 cores shared with
`ab`); rounds 1 and 2 measured 630 to 850 req/s on the same page.

### Backend

| Scenario | Result |
| --- | --- |
| `backend-load.js`, 5 users, `/typo3/main` + Web>Layout + record list, 2 min | 915 req, avg 61 ms, median 39 ms, p95 136 ms, max 737 ms, 100 % checks |
| `backend-multiuser.js`, 2 admin + 2 editor users, 3 min | 7004 req, avg 16 ms, median 15 ms, p95 31 ms, 100 % checks: no role marker crossed users |

### Stress, spike, mixed, soak

| Scenario | Result |
| --- | --- |
| `frontend-stress.js`, ramp to 100 users, 5 min | 177 678 req at 592 req/s, avg 69 ms, median 64 ms, p95 150 ms, max 336 ms, 0 failed |
| `frontend-spike.js`, burst to 200 users, 90 s | 80 049 req at 889 req/s, avg 150 ms, median 167 ms, p95 233 ms, max 265 ms, 0 failed |
| `mixed-workload.js`, 8 anonymous + 2 backend users, 5 min | 3086 req, frontend avg 23 ms / p95 32 ms, backend avg 35 ms / p95 86 ms, max 564 ms, 0 failed, 100 % checks |
| `frontend-soak.js`, 10 users with think time, 10 min | 5760 req, avg 26 ms, median 27 ms, p95 35.7 ms, max 47.8 ms, 0 failed, 100 % checks |

Soak memory, sampled every 30 s: RSS 282 to 283 MB for the whole ten minutes, `X-FrankenPHP-Discarded`
constant at 67. No drift, no recycle within the window (`MAX_REQUESTS=10000`).

### Page render harness: cold and warm per page, worker versus per-request PHP

`Tests/perf/perf.sh` (ported from the TAE harness, see `Tests/perf/README.md`) drops the `pages`
and `hash` caches before each sequence and measures TTFB of the cold call and two warm calls, 3
repeats, median, fresh connection per call (about 2 ms of every number is the TLS handshake).
Same FrankenPHP binary and Caddyfile for both rows; only the entry script differs.

| Page | worker cold | per-request cold | worker warm | per-request warm |
| --- | --- | --- | --- | --- |
| start page | 31.8 ms | 47.2 ms | 5.6 ms | 12.2 ms |
| route comparison | 35.1 ms | 48.9 ms | 5.7 ms | 12.0 ms |
| packing list | 34.6 ms | 46.2 ms | 5.6 ms | 12.4 ms |
| FAQs | 32.1 ms | 81.8 ms | 5.5 ms | 16.7 ms |
| privacy | 25.6 ms | 51.6 ms | 5.5 ms | 13.4 ms |
| imprint | 24.9 ms | 35.9 ms | 5.6 ms | 12.3 ms |
| **total, 6 pages** | **184 ms** | **312 ms (-41 % with the worker)** | **33.5 ms** | **79.0 ms (-58 % with the worker)** |

The control run (`worker-recheck`, identical code) came out at 208 ms cold / 45 ms warm, i.e.
+13 % / +36 % on the totals, all of it from one page (`packing list` 52 ms cold, 16.8 ms warm in
that run against 35 / 5.6 ms otherwise). Anything below that is noise on this machine; the worker
versus per-request difference is far above it on every page. The warm number is the one that
matters for a cached site: 5.6 ms against 12.3 ms per page, the bootstrap that the worker keeps.

Per-request PHP through FrankenPHP (classic mode, 12 threads) also **aborted the process** when
`frontend-latency.js` hit it at 300 req/s (`SIGABRT` on a PHP thread inside the FrankenPHP
binary, crash report `frankenphp-2026-09-06-163732.ips`); the worker took the same run 20 times
today without incident. Leak check (`perf-leak.sh`, canonical, title, `lang`, CSP nonce fresh
per request and equal to the header, bodies identical across passes): all 18 responses (6 pages, cold + two warm passes) ok in worker mode.

## Round 7: MariaDB and APCu (2026-09-06, evening)

The first item of the deployment list below, measured. MariaDB 12.3.3 (Homebrew, Unix socket
`/tmp/mysql.sock`, defaults) and APCu 5.1.29-dev (built from git main for PHP 8.5, 256 MB) on the
same machine, three sandboxes with byte-identical code and content (7 pages, 50 content elements):

| | SQLite + `FileBackend` (shipped) | MariaDB + `FileBackend` | MariaDB + `ApcuBackend` |
| --- | --- | --- | --- |
| `/camino/` one client, keep-alive mean | **3.6 ms** | 5.9 ms | 6.2 ms |
| `/camino/faqs` one client | **3.0 ms** | 7.9 ms | 8.7 ms |
| 300 req/s, 6 cached pages: avg / p95 | **4.0 / 4.2 ms** | 7.1 / 8.7 ms | 9.4 / 10.9 ms |
| `ab -k -c 8`, cached start page | 1077 req/s | **1107 req/s** | 796 req/s |
| harness cold total (6 pages, median) | **256 ms**\* | 386 ms | 392 ms |
| harness warm total (6 pages) | **59 ms**\* | 64 ms | 72 ms |
| backend `/typo3/main`, one client | **19 ms** | 34 ms | 36 ms |
| Web > Layout / record list, one client | **40 / 39 ms** | 68 / 71 ms | 72 / 90 ms |
| `backend-load.js`, 5 users: median / p95 | 34 / 136 ms | 58 / **106 ms** | 70 / 111 ms |
| `backend-multiuser.js`: median / p95 | **14 / 30 ms** | 17 / 43 ms | 18 / 57 ms |

\* two of the six SQLite pages hit one-off spikes in this run (packing list 74 ms, FAQs 59 ms
cold); the same pages measured 35 and 32 ms in the two earlier SQLite runs, i.e. the SQLite
totals are pessimistic here, not optimistic. All runs on a quiet machine (load average below 4);
a first pass taken while PhpStorm indexed the new sandboxes was discarded.

What the numbers say:

- **A cached page pays for its eight queries as round trips.** SQLite answers them in-process
  from the OS page cache; MariaDB over the socket adds roughly 0.3 to 0.5 ms each, which is the
  3 ms between 4.0 and 7.1 ms at 300 req/s, and the doubling of the cold render on the small
  pages (the same queries, many more of them). The query count is Core's, not the worker's.
- **MariaDB wins only where queries are heavy**: `backend-load.js` p95 136 to 106 ms. Everywhere
  else the sandbox's data set is too small for a server database to pay for its round trips.
  Throughput at saturation is the same (1077 vs 1107 req/s): the CPU cost per page is unchanged,
  the round trips are latency, not work.
- **TYPO3's `ApcuBackend` is slower than `FileBackend` on a threaded worker pool.** APCu on ZTS
  serialises with locks across all worker threads, and the backend stores tags as extra entries
  (TYPO3's own documentation calls it O(2n) with the number of tags), so the tagged `pages` and
  `hash` caches turn a shared-memory read into lock contention: 796 against 1107 req/s. A first
  APCu pass measured without the extension actually loaded (a bare `extension=apcu` resolves
  against the compiled-in extension directory, not the `pecl` one) is not in the table.
- Everything passed its correctness checks in all three variants: 100 % k6 checks, backend
  modules rendered, no worker errors.

Decision: the sandbox stays on SQLite with `FileBackend`. `scripts/setup-typo3.sh` now provisions
either variant (`TYPO3_DB_DRIVER=mysqli`, `TYPO3_CACHE_BACKEND=apcu`, `BUILD_DIR=…`) so the
comparison can be repeated on a machine with real data; the `Build-mariadb*` sandboxes from this
run remain next to `Build/`. For a production site with a large database the first bullet still
holds, and the second is where MariaDB or MySQL earns its place.

## Round 8: where the milliseconds go (2026-09-07)

Measured with the request profiler (`Documentation/Profiling.md`, `FRANKENPHP_PROFILE=1`): per
middleware own time, Core's TimeTracker spans, spans between Core events, and every query. 24 warm
requests per URL after 3 warm-ups, medians; SQLite sandbox, 10 workers, load average 3 to 7 on a
machine shared with the user's IDE, so absolute values are 10 to 30 % above the quiet-machine gate
numbers and the *shares* are the result.

### Cached frontend page (`/camino/imprint`, median total 8.4 ms with the profiler on)

| span | own ms | share | queries | what it is |
| --- | --- | --- | --- | --- |
| `prepare-tsfe-rendering` (middleware) | 5.30 | 63 % | 9 | of which: |
| `Create PageInformation` (TimeTracker) | 4.66 | 55 % | 9 | `PageInformationFactory::create()` |
| PageInformation: language, content_from_pid, **backend layout**, access | 4.11 | 49 % | 0 | see below |
| `page-resolver` (middleware) | 0.51 | 6 % | 1 | slug routing |
| page cache read + `createSetupConfigOrFullSetup` | 0.32 | 4 % | 0 | 1 `require`+`unserialize` (page cache) + 1 `require`+`unserialize` (setup-config) |
| `response-propagation` (middleware, 2 calls) | 0.28 | 3 % | 0 | |
| `site` (middleware) | 0.26 | 3 % | 0 | site matching, `setlocale()` |
| PageInformation: page record + rootline | 0.24 | 3 % | 2 | `pages` query, `cache_rootline` read |
| `createSettingsAndSetupConditions` | 0.23 | 3 % | 0 | 2 `require`+`unserialize` (settings) |
| `redirecthandler` (middleware) | 0.21 | 3 % | 0 | redirect list from the file cache |
| PageInformation: sys_template rows | 0.21 | 3 % | 1 | the one uncached query |
| `authentication` (middleware) | 0.08 | 1 % | 0 | |
| worker reset before the request | 0.03 | 0.3 % | | |
| rest (dispatcher, `Application` construction, response emit) | 0.85 | 10 % | | |

Database time in total: 0.13 ms for 10 queries. **The page is CPU-bound, and half of it is one
call.** Temporary marks inside Core (removed afterwards) split the 4.1 ms of "language,
content_from_pid, backend layout, access": `settingLanguage()` 3 µs, `setContentFromPid()` 1 µs,
`checkBackendUserAccess()` 1 µs, **`setPageLayout()` everything else**. Inside it,
`PageTsConfigFactory::create()` builds the complete page TSconfig on every request to resolve the
backend layout: rootline and data-provider preparation ~0.9 ms, include tree from the `typoscript`
cache (15 files, 358 KB, `require` + `unserialize`) ~1.4 ms, condition matching + AST build ~1.3 ms,
`toArray()` and layout resolution ~1.0 ms (noisy run; a quiet run put the whole block at 1.9 ms).
No database is involved. The same work runs under PHP-FPM on every request; only the worker makes
it visible as the largest remaining item.

### Backend

| request | median total | largest own spans | queries |
| --- | --- | --- | --- |
| `/typo3/login` | 3.7 ms | `response-propagation` 1.4 ms (290 KB body, 2 calls), `authentication` 0.8 ms, `csp-headers` 0.4 ms | 5 |
| `/typo3/main` (shell) | 21.7 ms | controller 17.7 ms\*, `response-propagation` 1.4 ms, `authentication` 0.8 ms, `csp-headers` 0.4 ms | 12 (0.4 ms) |
| Web > Layout, page 1 | 43.6 ms | controller 36.2 ms\*, `page-context` 2.9 ms, `response-propagation` 1.3 ms | **119 (1.6 ms)** |
| Record list, page 1 | 46.9 ms | controller 39.2 ms\*, `page-context` 3.0 ms, `response-propagation` 1.3 ms | **160 (1.8 ms)** |

\* the innermost middleware's own time is the request handler, i.e. the module controller.
The backend is CPU in the controllers (module rendering, Fluid, labels) plus a query count that
SQLite hides: 119 to 160 queries at 10 µs each are 1.6 ms here, and 30 to 50 ms over a MariaDB
socket (round 7 measured exactly that penalty on the same pages).

### Ranked bottlenecks and what to do about them

| # | bottleneck | measured | solution | who |
| --- | --- | --- | --- | --- |
| 1 | page TSconfig built per frontend request for the backend layout | ~2 ms of a 4 ms page (49 %) | Core: cache the compiled `PageTsConfig` (AST + array) in the `typoscript` or `hash` cache keyed by include-tree hash + condition verdicts, the way `FrontendTypoScriptFactory` caches setup; or resolve the layout without the full TSconfig. Extension: the mtime-validated memo below removes the `unserialize` share | upstream + extension (Stage 3) |
| 2 | Core middleware pipeline for a cached page | 22 middlewares, 3.9 ms | serve cached anonymous pages as static files from Caddy (EXT:staticfilecache) | deployment (Stage 4) |
| 3 | `unserialize()` of identical `typoscript` cache entries per request | 4 frontend entries ~0.5 ms, page TSconfig tree ~1.4 ms of loading | `WorkerPhpFrontend` memo (shipped, see below): median total 4.05 → 3.66 ms, page-TSconfig block 1.76 → 1.20 ms, A/B back to back | extension (Stage 3) |
| 4 | `response-propagation` runs twice (core + application stack) | 0.28 ms frontend, 1.4 ms backend | Core: propagate once, or stream large bodies | upstream |
| 5 | `sys_template` query on cache hits | 0.21 ms, 1 query | Core TODO: join into the rootline CTE | upstream |
| 6 | redirects read from the pages cache per request | 0.21 ms | Core/EXT:redirects: runtime memo per request | upstream |
| 7 | backend query counts (119-160 per module render) | 1.6-1.8 ms on SQLite, 30-50 ms on MariaDB | Core: fewer per-record lookups in `BackendLayoutRenderer`/record list; deployment: keep the database in-process or on the same host | upstream, deployment |
| 8 | worker reset | 0.03 ms visible | done (round 4) | extension |

### Stage 3 shipped: in-process memo for the `typoscript` cache

`Classes/Cache/WorkerPhpFrontend` replaces Core's `PhpFrontend` for the `typoscript` cache under
FrankenPHP (`ext_localconf.php`, `PHP_SAPI === 'frankenphp'`). Core stores TypoScript and TSconfig
trees as `return unserialize('…')` files; OPcache keeps the compiled file, but the `unserialize()`
ran on every request. The memo keeps the unserialized value per entry and validates it with the
file's mtime (one stat, where the backend already paid one), so a cache flush or rewrite in any
process is seen on the next request; entries are content-addressed, bounded to 64. A/B on the same
machine, 40 requests each, `/camino/` exact:

| | before | after |
| --- | --- | --- |
| median total | 4.05 ms | 3.66 ms (−10 %) |
| PageInformation: backend layout (page TSconfig build) | 1.76 ms | 1.20 ms |
| middleware stack own time | 3.58 ms | 3.20 ms |

The remaining 1.2 ms of the page-TSconfig block is condition matching, AST building and
`toArray()`, which only Core can cache (bottleneck 1).

### Stage 4 shipped: EXT:staticfilecache served by Caddy

`frankenphp:init --static-file-cache` adds an `@sfc` matcher and handler in front of the asset
handler: GET or HEAD, no query string, no `be_typo_user` cookie, no `staticfilecache=typo_user_logged_in`
cookie, no crawler or Solr indexer header, and the file
`typo3temp/assets/tx_staticfilecache/<scheme>_<host>_<port><path>/index` exists; then Caddy serves
it (gzip sidecar via `precompressed gzip`, `Content-Type` forced, direct access to the cache directory
denied). Everything else, and every request with a backend or logged-in frontend user, still reaches
TYPO3. The sandbox has `lochmueller/staticfilecache` 16.1 installed with the plain and gzip
generators on and the PHP fallback middleware off; the first render of a page writes the files, and
TYPO3's cache flush removes them. Expiry is not checked by Caddy: schedule the extension's
`staticfilecache:removeExpiredPages` command.

| `/camino/` | worker (round 4) | static file via Caddy |
| --- | --- | --- |
| one client, keep-alive, mean | 3.6 ms | 1.0 ms (p50 below 1 ms) |
| 300 req/s: avg / p95 | 4.0 / 4.2 ms | **0.38 / 0.69 ms**, 0 dropped, 100 % checks |
| 1000 req/s: avg / p95 | k6 saturates the machine at 400 | k6 saturates the machine (25 040 dropped iterations); the server is not the limit |
| `ab -k -c 8` ceiling | 1077 req/s | **7106 req/s**, p50 1 ms, p99 2 ms, 0 failed |

The bypass rules were verified one by one (query string, backend cookie, frontend-user cookie,
POST, direct cache path 403, backend and install tool untouched); the leak check and the frontend
checks pass against the static files. The worker keeps serving everything that is not a plain
anonymous page: logged-in users, forms, the backend, uncached plugins.

## What the deployment has to do

1. A real database server on a Unix socket for sites whose queries are heavy (round 7: MariaDB
   helped the backend load scenario only; on the small sandbox SQLite in-process is faster). Redis
   for the hot caches; not `ApcuBackend` on a threaded worker pool (round 7).
2. Cores. Throughput scales with cores until they are busy; latency at a given request rate follows.
3. `TYPO3_CONTEXT=Production`, `FE.debug=0`, no `adminpanel`.
4. Threads about two per core (`FRANKENPHP_NUM_THREADS` two above the worker count),
   `MAX_REQUESTS` at 10000 or higher (each worker jitters its own limit by up to 10 %),
   `MAX_MEMORY_MB` as a leak guard, a supervisor with `Restart=always`. `max_threads auto` and
   `max_wait_time` are commented examples in the generated Caddyfile; `GOGC` / `GOMEMLIMIT`
   in `.env`.
5. Measure at a request rate with think time, not in a closed loop, when the question is
   "how fast is a page": the closed-loop average only reports how many clients share the CPU.
6. For sub-millisecond cached pages, a static or edge cache in front of the worker.

## Round 5: experiments that did not earn a config change

Each candidate was run against the same sandbox at 300 req/s (`frontend-latency.js`, 60 s); the
reference from round 4 is avg 3.8-4.0 ms, p95 4.5-4.6 ms, with run-to-run noise of about 0.2 ms.

| Experiment | Result | Decision |
| --- | --- | --- |
| `opcache.jit=function` / `tracing`, 64 MB buffer | Not runnable: the Homebrew FrankenPHP 1.12.6 binary (PHP 8.5.9 ZTS, arm64) ships OPcache without the JIT. `opcache_get_status()` has no `jit` section and no `opcache.jit*` ini entry exists. | Nothing to enable; the prod `php.ini` comment stays. Re-test on a build that carries the JIT (Linux x86_64 packages). |
| `opcache.preload` | Not run. A worker process compiles every class once at boot and keeps it in the shared OPcache for its lifetime; preloading would move the same compilation from boot to startup. Only the per-request `?__typo3_install` path could gain, and it is not a hot path. | Skip. |
| `GOGC=200` | avg 4.08 ms, p95 4.87 ms; RSS 364 MB after the run. | No gain, more memory; not adopted. Documented as a knob in `.env`. |
| `GOMAXPROCS` | Defaults to all cores, which is already what php/frankenphp#382 recommends over the PHP thread count. | Nothing to change. |
| `encode zstd gzip` removed | avg 3.98 ms, p95 4.72 ms, 1.3 GB transferred instead of 0.3 GB. | Compression is free at this size on this machine (it runs in Go, off the PHP threads); keep it. |
| Native MariaDB on a Unix socket instead of SQLite | Not run: no MariaDB/MySQL server is installed on this machine, and installing one changes the machine beyond this repository. | Open; the sandbox stays on SQLite until a server exists. |

## Stability

FrankenPHP 1.12.6 with PHP 8.5.9 ZTS on macOS aborted four times today: once with the tracing JIT,
twice with `SIGABRT` inside its own binary during 8-thread runs (once before and once after the
connection change), and once with `SIGSEGV` under a recycle storm (`MAX_REQUESTS=500` at 800
requests per second). The crash reports are in `~/Library/Logs/DiagnosticReports/frankenphp-*.ips`.
The higher `MAX_REQUESTS` default removes the reproducible trigger; the others need upstream
attention.
