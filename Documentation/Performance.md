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
| Worker reset (`WorkerStateResetter::reset()`) | 0.2 ms | this extension |
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

## What changed

- **Database connections survive requests** (`ConnectionRecycler`, closed after an open
  transaction or 60 s idle).
- **Sandbox caches on `FileBackend`** (`scripts/setup-typo3.sh`); production equivalents are
  Redis or APCu.
- **`frankenphp:init` defaults**: development one worker thread per core, production two per
  core, `MAX_REQUESTS=10000` for both. A recycle every 500 requests at 800 per second is 16 worker
  restarts per second and reproducibly aborted FrankenPHP 1.12.6.
- **The Caddyfile now carries the chosen values as placeholder defaults.** Caddy reads `.env`
  only with `--envfile`; the README's earlier `-e .env` was silently ignored (Caddy tolerates
  unknown flags), so ports and worker counts in `.env` never reached the Caddyfile. PHP still saw
  `.env` through `helhum/dotenv-connector`, which hid the mistake. `frankenphp:init` now prints
  the correct command.

## What the deployment has to do

1. A real database server on a Unix socket instead of SQLite, and Redis or APCu cache backends.
   Not measurable here: MariaDB is only available through Docker Desktop's VM network on this
   machine, and no native server is installed.
2. Cores. Throughput scales with cores until they are busy; latency at a given request rate follows.
3. `TYPO3_CONTEXT=Production`, `FE.debug=0`, no `adminpanel`.
4. Threads about two per core, `MAX_REQUESTS` at 10000 or higher, a supervisor with
   `Restart=always`.
5. Measure at a request rate with think time, not in a closed loop, when the question is
   "how fast is a page": the closed-loop average only reports how many clients share the CPU.
6. For sub-millisecond cached pages, a static or edge cache in front of the worker.

## Stability

FrankenPHP 1.12.6 with PHP 8.5.9 ZTS on macOS aborted four times today: once with the tracing JIT,
twice with `SIGABRT` inside its own binary during 8-thread runs (once before and once after the
connection change), and once with `SIGSEGV` under a recycle storm (`MAX_REQUESTS=500` at 800
requests per second). The crash reports are in `~/Library/Logs/DiagnosticReports/frankenphp-*.ips`.
The higher `MAX_REQUESTS` default removes the reproducible trigger; the others need upstream
attention.
