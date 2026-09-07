# Implementation plan: round 8, measure where a request's time goes, then remove it

Approved plan: `~/.claude/plans/do-intense-research-on-stateless-waffle.md`.

## Stage 1: Request profiler (development only, no vendor patches)
**Goal**: per-middleware, TimeTracker and DB timing per request; `Server-Timing` header; JSONL log; `frankenphp:profile` report.
**Success Criteria**: `curl -sI https://localhost:8885/camino/ | grep Server-Timing` lists middleware spans when `FRANKENPHP_PROFILE=1`; `frankenphp:profile --uri=/camino/` prints medians; production container has no profiler services.
**Tests**: `Tests/Unit/Profiler/*`, `Tests/e2e/module/server-timing.spec.ts`, `frankenphp:audit --filter=Profiler`.
**Status**: Complete

## Stage 2: Measure and rank
**Goal**: profiled medians for 6 frontend pages and 3 backend requests; ranked bottleneck table with decisions in Performance.md round 8; Core findings in WorkerMode.md; Documentation/Profiling.md.
**Success Criteria**: every bottleneck row carries a measured number.
**Tests**: none beyond the measurements.
**Status**: Complete

## Stage 3: In-process memo for the typoscript cache
**Goal**: `WorkerPhpFrontend` with mtime-validated memo for `cache.typoscript` under FrankenPHP.
**Success Criteria**: TypoScript spans drop measurably at 300 req/s; e2e `typoscript`/`page-tsconfig` green; leak check ok. Gate: Stage 2 shows >= 0.2 ms.
**Tests**: `Tests/Unit/Cache/WorkerPhpFrontendTest.php`.
**Status**: Complete (-10 % median total)

## Stage 4: Static file cache served by Caddy
**Goal**: EXT:staticfilecache in the sandbox, `frankenphp:init --static-file-cache` Caddy block, measured.
**Success Criteria**: cached anonymous pages served by Caddy (~0.3 ms), logged-in/backend/install requests bypass; `InitCommandTest` covers the option.
**Tests**: InitCommandTest, perf-leak.sh, k6 frontend checks.
**Status**: Not Started
