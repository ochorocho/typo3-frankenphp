# Implementation plan: discard-by-default worker-mode service lifecycle

Full design: see the approved plan (whitelist stateless services, discard the rest per
request, dependency-closed keep set, `RequestId` rotation, `cache.runtime` flush).

## Stage 1: Compiler pass + audit command
**Goal**: `WorkerKeepListPass` classifies every shared service (keep / discard, with
reason), enforces dependency closure, stores the result as container parameters.
`frankenphp:audit` prints it.
**Success Criteria**: `typo3 frankenphp:audit` lists keep/discard groups with reasons;
PHPStan + php-cs-fixer clean; unit test green. No runtime behaviour change yet.
**Tests**: `Tests/Unit/DependencyInjection/WorkerKeepListPassTest.php`
**Status**: Complete

## Stage 2: WorkerStateResetter + per-request application fetch
**Goal**: Replace the `StateSnapshotService` blacklist with: globals reset, `RequestId`
rotation, keep hooks, selective container wipe, `PageRenderer` re-seed.
**Success Criteria**: Playwright chromium suite green incl. `csp-nonce-uniqueness`;
k6 soak scenarios pass; discarded count stable across requests.
**Tests**: existing e2e + un-fixme'd CSP nonce spec
**Status**: Complete

## Stage 3: Full cache.runtime flush, Core patch removal
**Goal**: `cache.runtime->flush()` per request replaces the 8-key denylist; drop
`Patches/` and the manual composer patch step.
**Success Criteria**: login/re-login/page-tree specs green on pristine cms-core.
**Tests**: `form-token-after-relogin`, `worker-state-isolation`, `iframe-nesting-guard`
**Status**: In Progress

## Stage 4: Curate keep-list, static-leak shims, new e2e
**Goal**: Zero demoted PINNED entries in the audit; static Core leaks shimmed with
upstream references; storage permission isolation covered by e2e.
**Success Criteria**: audit clean; e2e + k6 green.
**Tests**: `storage-permission-isolation.spec.ts`
**Status**: Not Started

## Stage 5: Performance budget and cleanup
**Goal**: k6 before/after within budget (p95 +10 %, throughput -10 %); docs updated.
**Success Criteria**: numbers recorded; CLAUDE.md / README updated; this file removed.
**Tests**: `Tests/load/frontend-load.js`, `backend-load.js`
**Status**: Not Started
