# Profiling a worker request

Where does a request spend its time? The extension answers that in Development context without
patching a single vendor file, and without a profiler that moves the numbers it measures: two
`hrtime()` calls per frame, written out after the response.

## Enable

```bash
# Build/.env is Development; the profiler is compiled into Development containers only.
cd Build && vendor/bin/typo3 cache:flush                        # once after changing TYPO3_CONTEXT
FRANKENPHP_PROFILE=1 frankenphp run -c $PWD/Caddyfile --envfile $PWD/.env
```

Every response then carries a `Server-Timing` header, and every request appends one JSON line to
`var/log/frankenphp-profile.jsonl`. Production containers contain none of the profiler services;
a Development worker started without the variable pays one boolean check per middleware and query.

```bash
curl -skD - -o /dev/null https://localhost:8885/camino/ | grep -i server-timing
# server-timing: total;dur=3.91, db;dur=0.62;desc="4 queries", frontend.prepare-tsfe-rendering;dur=1.73;desc="typo3/cms-frontend/prepare-tsfe-rendering", ...
```

## Report

```bash
for i in $(seq 1 24); do curl -sk -o /dev/null https://localhost:8885/camino/; done
scripts/typo3 frankenphp:profile --uri=/camino/            # medians over the matching requests
scripts/typo3 frankenphp:profile --uri=/typo3/ --kind=middleware --min-ms=0.1
scripts/typo3 frankenphp:profile --format=json
```

The report follows the TAE performance harness: **median, never mean** (one warm-up outlier would
dominate), per-request sums before medians (so the summary lines belong to one request's terms),
`share = median own / median total`, spans below 0.05 ms hidden. Columns:

| column | meaning |
| --- | --- |
| `own ms` | time inside the span minus its nested spans of the same kind |
| `incl ms` | wall time of the span including everything it called |
| `share` | own time as share of the median total |
| `calls` | how often the span opened per request (a middleware appearing in the `core` and the `frontend` stack counts twice) |
| `db ms`, `db calls` | queries executed while this span was the innermost open one |

## What is recorded

| kind | source | how |
| --- | --- | --- |
| `middleware` | every PSR-15 middleware of the `core`, `frontend` and `backend` stacks, named by its identifier | `ProfilingMiddlewareStackResolver` decorates Core's `MiddlewareStackResolver` and hands `MiddlewareDispatcher` a `TimedMiddleware` per entry; the real middleware is still resolved lazily per request |
| `timetracker` | Core's own `TimeTracker::push()/pull()` pairs: `Create PageInformation`, `Page generation`, … (depth 3, `FRANKENPHP_PROFILE_DEPTH`) | the `TimeTracker` service class is `ProfilingTimeTracker`; Core's enabled/disabled logic is untouched |
| `service` | `FrontendTypoScriptFactory::createSettingsAndSetupConditions`; page cache read plus `createSetupConfigOrFullSetup` | spans between events, because those classes are `final readonly` |
| `db` | every query, count and time, attributed to the innermost open middleware and TimeTracker frame | a Doctrine driver middleware registered through `globalDriverMiddlewares` |
| `worker` | reset before the request, structural reset after the previous response, reset mode, discarded instances | from `WorkerRequestCycle` |

Marks (`pageinformation.done`, `settings.done`, `systemplate.determined`, `typoscript.determined`,
`redirect.match`) are stored per request in the log line for ad-hoc analysis.

## Reading the numbers

- The `total` in `Server-Timing` is the time from the worker's `begin()` to the moment the header
  is written, i.e. without response emission; the log line's `total_ns` runs until after the
  response was handed to `frankenphp_finish_request()`.
- **The innermost middleware's own time includes the request handler**: the controller or page
  renderer below the stack is not a middleware, so its time lands on whichever middleware sits last
  (in the backend stack that is `ochorocho/frankenphp/preserve-native-session-cookies`, so its 18 ms
  on `/typo3/main` is the backend controller, not the cookie fix).
- `--uri` matches a substring; `/camino/` matches every Camino page. Use `--exact` for one page.
- Own time of `prepare-tsfe-rendering` minus `Create PageInformation` minus the settings span is
  the page cache read and TypoScript setup work; the report does not invent a span for it.
- A middleware whose own time is dominated by `db ms` is waiting on the database; one with a large
  own time and no queries is computing.
- `rest` in the summary is what neither the reset nor a middleware accounts for: the dispatcher,
  building the `Application`, emitting the response.

## Pitfalls

- The DI container and the middleware stack cache are compiled per project, not per context:
  after switching `TYPO3_CONTEXT`, run `cache:flush`, or the worker keeps the other context's
  container.
- The collector is a pinned service that survives the per-request discard on purpose; it holds
  scalars only. `frankenphp:audit --filter=Profiler` shows it as `keep / pinned`.
- Do not measure throughput with the profiler on: a JSON line per request costs a file append.
