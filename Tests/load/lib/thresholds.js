/**
 * Shared k6 thresholds.
 *
 * Failing a threshold in k6 marks the run as failed (exit code 99 by
 * default). Numbers calibrated for the dev sandbox (SQLite + 2-worker
 * FrankenPHP on a developer laptop) — tighten them once a production
 * baseline exists.
 *
 * All `http_req_*` gates are tag-scoped to `{phase:measured}`. Iteration
 * requests inherit that tag automatically via `REQUEST_PARAMS` /
 * `BACKEND_REQUEST_PARAMS`; `setup()` warmup requests use
 * `WARMUP_REQUEST_PARAMS` (tag `phase=warmup`) and are intentionally
 * excluded — Caddy's `tls internal` cert generation and the first worker
 * boot can produce sub-millisecond TLS-handshake failures on a cold CI
 * runner, and those samples would otherwise drag `http_req_failed` over
 * the 1% gate before the test has even started measuring.
 */

// Used by smoke / load / soak scenarios. p95 < 1000 ms covers a healthy
// anonymous frontend response on the worker mode hot path even under
// the shared-VM noise of GitHub-hosted runners (typical anonymous
// frontend hits clock in at ~10–50 ms on the dev sandbox, so 1 s still
// catches a 20×+ regression). p99 < 3000 ms is intentionally loose
// because the very first request after frankenphp boot pays the
// worker-warmup cost (5–7 s on a cold container) and that one sample
// dominates the 99th percentile in short smoke runs.
//
// The previous tighter p95 < 500 ms was tripped intermittently in CI on
// noisy-neighbour runners despite a per-worker setup() warmup loop —
// the noise sits ABOVE the boot cost, not inside it. Raising p95 to 1 s
// trades a small amount of regression-detection sensitivity for stable
// CI runs. If you ever see this trip again in CI, the right next step
// is usually to drop the per-percentile latency gate entirely and rely
// on http_req_failed.rate + checks.rate alone (latency gating belongs
// on dedicated perf infrastructure, not shared CI).
export const defaultThresholds = {
    'http_req_duration{phase:measured}': ['p(95)<1000', 'p(99)<3000'],
    'http_req_failed{phase:measured}':   ['rate<0.01'],
    checks:                              ['rate>0.99'],
};

// Backend (authenticated) requests trip a heavier code path —
// WorkerStateResetter runs on every request, plus TYPO3 backend hits
// the session DB. p95 < 2000 ms is a CI-noise-tolerant expectation
// under steady load (was 1000 ms; bumped for the same reason as
// defaultThresholds — see its docblock).
export const backendThresholds = {
    'http_req_duration{phase:measured}': ['p(95)<2000', 'p(99)<3000'],
    'http_req_failed{phase:measured}':   ['rate<0.02'],
    checks:                              ['rate>0.98'],
};

// Stress / spike scenarios deliberately drive the server to saturation;
// p95 above 500 ms is the *expected* outcome past the breaking point.
// The threshold here only catches "completely broken" — high error rate
// or thresholds we never want to violate even at peak.
export const saturationThresholds = {
    'http_req_duration{phase:measured}': ['p(95)<3000', 'p(99)<10000'],
    'http_req_failed{phase:measured}':   ['rate<0.10'],
    checks:                              ['rate>0.90'],
};

// Install-tool failsafe runs as per-request PHP (no worker) — slower
// boot per request, so allow looser timings than worker-mode frontend.
// p95 raised from 1500 ms to 3000 ms for CI-noise tolerance (see
// defaultThresholds for the full rationale); the failsafe path's
// natural latency floor on a cold CI runner already sits in the
// hundreds of milliseconds, so headroom matters more here.
export const installToolThresholds = {
    'http_req_duration{phase:measured}': ['p(95)<3000', 'p(99)<5000'],
    'http_req_failed{phase:measured}':   ['rate<0.01'],
    checks:                              ['rate>0.99'],
};
