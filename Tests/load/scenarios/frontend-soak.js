/**
 * Frontend soak test — 10 VUs × 10 min.
 *
 * The highest-signal scenario for THIS extension. Worker mode keeps the
 * PHP process alive across thousands of requests, so any singleton
 * state that fails to reset properly will manifest as:
 *
 *   - Latency drifting upward across the 10 min window (memory bloat
 *     pressuring GC, opcache thrashing).
 *   - Rising error rate near the end of the run (cumulative state
 *     corruption tripping an assertion).
 *
 * After the run, check that `http_req_duration` over the first minute
 * is roughly equal to the last minute (k6 prints the overall p50/p95
 * but you'll need `--out json=run.json` and post-process to see drift).
 * Or just eyeball the per-iteration log.
 *
 *   k6 run scenarios/frontend-soak.js
 *   k6 run --out json=soak.ndjson scenarios/frontend-soak.js  # for drift analysis
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { CONFIG, FRONTEND_PATHS, randomFrontendPath, REQUEST_PARAMS, WARMUP_REQUEST_PARAMS } from '../lib/config.js';
import { defaultThresholds } from '../lib/thresholds.js';
import { okStatus, looksLikeCaminoPage, noPHPError, noDuplicateAssets } from '../lib/checks.js';

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        soak: {
            executor: 'constant-vus',
            vus:      10,
            duration: '10m',
        },
    },
    thresholds: defaultThresholds,
};


/**
 * Cold-boot warmup — see frontend-smoke.js for rationale. The first
 * worker request after frankenphp boot pays a 1–3 s startup cost
 * that distorts percentile thresholds in short scenarios; running a
 * throwaway request from setup() (which is excluded from threshold
 * metrics) keeps the measured window clean.
 */
export function setup() {
    // Loop so the warmup hits every worker slot at least once — see
    // frontend-smoke.js for the full rationale. Tagged phase=warmup
    // via WARMUP_REQUEST_PARAMS so it's excluded from the
    // phase-scoped threshold gates.
    for (let i = 0; i < 10; i++) {
        http.get(`${CONFIG.baseUrl}${FRONTEND_PATHS[i % FRONTEND_PATHS.length]}`, WARMUP_REQUEST_PARAMS);
    }
}

export default function () {
    const res = http.get(`${CONFIG.baseUrl}${randomFrontendPath()}`, REQUEST_PARAMS);
    check(res, { ...okStatus, ...looksLikeCaminoPage, ...noPHPError, ...noDuplicateAssets });
    sleep(1);
}
