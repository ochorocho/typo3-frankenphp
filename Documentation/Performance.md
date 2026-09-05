# Performance review: what a request costs and how to get to 10 ms

Measured on 5 September 2026 against the `Build/` sandbox: TYPO3 14.3, FrankenPHP 1.12.6
(PHP 8.5.9 ZTS, Homebrew), SQLite for the database and every cache, the Camino demo site,
Apple Silicon (10 cores) shared with the load generator. The load scenario is
`Tests/load/scenarios/frontend-load.js`: 100 virtual users with 1 s think time, six cached
pages, about 95 requests per second. "Server-side" is Caddy's own per-request `duration`,
"in PHP" is the time inside `Application::run()` measured with a temporary probe.

## Where a cached frontend page spends its time

Sequential, one client, before any change: 9.3 ms end to end (curl with a fresh TLS handshake
per request), 7.9 ms server-side, 7.4 ms in PHP.

| Step | Cost | Owner |
| --- | --- | --- |
| Worker reset (`WorkerStateResetter::reset()`) | 0.2 ms | this extension |
| Rebuild of the 11 discarded services a frontend hit needs | 1.35 ms, 0.9 ms of it the dev-only admin panel renderer | this extension's model, Core constructors |
| `PrepareTypoScriptFrontendRendering` (page cache fetch) | 2.8 ms | Core, cache backend |
| `RedirectHandler` (redirect list from the pages cache) | 1.2 ms | EXT:redirects, cache backend |
| `PageResolver`, `SiteResolver`, `FrontendUserAuthenticator`, CSP, headers | 1.5 ms | Core |
| TYPO3 parse time (`X-TYPO3-Parsetime`) | 0 ms | cached page |
| Caddy, TLS, response transfer (74 KB, zstd) | 1.5 to 2 ms | Caddy |
| `gc_collect_cycles()` per request | under 1 µs | measured, no change needed |

The extension's own share is well under 1 ms. Two thirds of the PHP time are cache reads
from SQLite.

## Why 100 users saw 32 ms

The in-PHP time stayed at 7.2 ms under load; the rest was waiting. Two effects stacked:

1. **Queueing in front of PHP.** The development profile runs 2 worker threads, production ran 4.
   k6's users are synchronised by their 1 s sleep, so requests arrive in bursts.
2. **SQLite lock contention, amplified by a reconnect per request.** Every request opened a new
   SQLite connection (file open plus schema parse). With more threads that got worse, not
   better: at 8 threads the in-PHP time rose to 33 ms, the SQLite-reading middlewares grew 5 to
   17 times (redirect list 1.1 to 18 ms, page cache 2.6 to 8.5 ms, page resolver 0.4 to 1.9 ms)
   while CPU-only middlewares stayed flat.

| 100 users, 2 minutes | k6 median | server-side p50 | in PHP p50 |
| --- | --- | --- | --- |
| 2 threads, reconnect per request (before) | 35.1 ms | 34.8 ms | 7.2 ms |
| 8 threads, reconnect per request | 44.2 ms | 43.9 ms | 32.7 ms |
| 16 threads, reconnect per request | 40.1 ms | 39.7 ms | 37.3 ms |
| 8 threads, SQLite WAL, reconnect per request | 44.5 ms | 44.1 ms | 32.0 ms |
| 2 threads, connections kept | 27.2 ms | 26.9 ms | |
| 8 threads, connections kept | **12.9 ms** | **11.3 ms** | 13.7 ms |

Sequential with kept connections: 7.0 ms end to end (from 9.3 ms).

## What changed in the extension

- **Database connections survive requests** (`ConnectionRecycler`). Reconnecting per request
  was the dominant cost under load. A connection is closed only when the previous request died
  inside a transaction or after 60 s idle (`KeepList::CONNECTION_MAX_IDLE_SECONDS`), so the
  server's `wait_timeout` never wins. TYPO3 already supports this lifetime through
  `persistentConnection`, so a database session spanning requests is an accepted mode.
- **Production worker default is two threads per core** (minimum 4), because worker threads
  block on the database and requests queued in front of PHP long before the cores were busy.
- Not changed, measured as irrelevant: `gc_collect_cycles()` per request (under 1 µs), the
  runtime-cache flush, the reset itself.

Existing installations get the new `.env` default only with `frankenphp:init --force`; the
connection change is in the extension code and needs no regeneration.

## What the deployment has to do for 10 ms

The extension cannot deliver the rest; these are ordered by measured impact.

1. **MariaDB or MySQL over a Unix socket instead of SQLite.** SQLite serialises concurrent
   workers on file locks; it is the single biggest limiter under load. With kept connections a
   request costs only its query round trips.
2. **In-memory cache backends** for `pages`, `hash`, `rootline` and `pagesection`:
   `RedisBackend`, or `ApcuBackend` when the FrankenPHP build ships APCu (Homebrew's does not).
   That removes the 4 ms of SQLite cache reads per cached page.
3. **Production settings**: `TYPO3_CONTEXT=Production`, `FE.debug=0`, `displayErrors=0`, and no
   `adminpanel` extension (0.9 ms per request just to rebuild its renderer).
4. **Size the worker pool**: about two threads per core (now the default), and raise
   `MAX_REQUESTS` once memory is stable across a soak test. Each recycle costs a 1 to 2 s boot
   that is amortised over that many requests.
5. **Keep the JIT off** (see the php.ini comment; the tracing JIT crashed workers under load).
6. For sub-millisecond cached pages put a static or edge cache in front of the worker
   (`nc_staticfilecache`, or Caddy's cache module).

Expected results on that stack, cached frontend pages: p50 around 3 to 5 ms, p90 inside 10 ms
at 100 users on an 8-thread pool. Backend module requests stay at 15 to 30 ms: Core's
`BackendUserAuthenticator` alone spends 4 to 8 ms per request on session, user and group
lookups, and that is outside this extension's reach.

## Stability note

During six heavy runs with 8 to 16 threads, FrankenPHP aborted once with `SIGABRT` inside its
own binary and no message in the log (macOS crash report
`frankenphp-2026-09-05-211402.ips`). An identical abort had occurred earlier in the day with
the previous code, and a third crash was the tracing JIT. Treat FrankenPHP 1.12.6 with PHP 8.5.9
ZTS as needing a supervisor (`Restart=always`) and moderate thread counts; report the `.ips`
files upstream.
