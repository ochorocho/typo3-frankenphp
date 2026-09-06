# Implementation plan: faster worker requests

Approved plan: `~/.claude/plans/do-intense-research-on-stateless-waffle.md` (Stages 1–3 this round).

## Stage 1: Post-response reset
**Goal**: run the structural reset after `frankenphp_finish_request()`, keep only the clock-dependent part in front of the next request.
**Success Criteria**: second request reports `X-FrankenPHP-Reset-Mode: post`; `X-FrankenPHP-Reset-Us` drops from ~240 µs to well under 100 µs; e2e isolation suite green; `RATE=300` latency gate improves.
**Tests**: `Tests/Unit/Worker/WorkerStateResetterTest.php`, `Tests/Unit/Worker/WorkerRequestCycleTest.php`, `Tests/e2e/module/post-response-reset.spec.ts`, k6 `frontend-latency.js` mode check.
**Status**: Complete (code, unit tests, docs); e2e + k6 verification recorded below

## Stage 2: Reset internals
**Goal**: fix the table-info runtime-cache keep pattern (only matched SQLite `cache_*` tables), keep map computed once, prefix checks, no pool rewrite when nothing closed.
**Success Criteria**: MySQL-style and SQLite-style table-info keys are kept; unit tests for the policy; reset pre-phase cheaper.
**Tests**: `WorkerStateResetterTest` policy provider, `ConnectionRecyclerTest`.
**Status**: Complete (bind-once closures dropped: they run in the post phase now, invisible to clients)

## Stage 3: Runtime-cache keep expansion
**Goal**: dev-only runtime-cache inventory + `frankenphp:audit --runtime-cache` report; add content-addressed keep groups with measurements.
**Success Criteria**: inventory log written for frontend and backend requests; report groups keys; each new pattern measured and documented.
**Tests**: unit tests per pattern, `page-tsconfig.spec.ts`, `backend-multiuser.js`.
**Status**: In Progress (inventory + report done; classification and measurement pending)
