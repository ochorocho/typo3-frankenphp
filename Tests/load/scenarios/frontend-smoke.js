/**
 * Frontend smoke test — 1 VU × 30 s.
 *
 * Cheapest possible sanity check: a single virtual user requests every
 * Camino frontend route in a loop for 30 s. Catches "server is down",
 * "frontend rendering is broken", "TLS cert handshake fails" before
 * spending minutes on a larger scenario.
 *
 *   k6 run scenarios/frontend-smoke.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { CONFIG, FRONTEND_PATHS, REQUEST_PARAMS, WARMUP_REQUEST_PARAMS } from '../lib/config.js';
import { defaultThresholds } from '../lib/thresholds.js';
import { okStatus, looksLikeCaminoPage, noPHPError, noDuplicateAssets } from '../lib/checks.js';

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        smoke: {
            executor: 'constant-vus',
            vus:      1,
            duration: '30s',
        },
    },
    thresholds: defaultThresholds,
};

/**
 * One-off worker-pool warmup before the measured iterations begin.
 *
 * Contrary to a common misreading of the k6 docs, requests issued from
 * `setup()` DO feed the global `http_req_*` metrics — they are not in a
 * separate scope. We tag them with `phase=warmup` via
 * `WARMUP_REQUEST_PARAMS` so the phase-scoped thresholds in
 * `lib/thresholds.js` skip them; otherwise a single TLS-handshake hiccup
 * during Caddy's first-request `tls internal` cert generation
 * (sub-millisecond `http_req_duration` failures) would trip
 * `http_req_failed{phase:measured} < 0.01` before measurement starts.
 *
 * Why we loop: a single warmup request only warms ONE worker, but the
 * dev profile sets `FRANKENPHP_WORKER_COUNT=2`. When iteration 1's
 * request happens to land on the still-cold worker B, that 2 s outlier
 * latches onto the p95 (top 5% ≈ 2 samples of a ~50-sample smoke run)
 * and trips the latency gate. Hammering setup() with a handful of
 * requests reliably hits every worker slot once before measurement
 * starts; 10 is enough headroom for `FRANKENPHP_WORKER_COUNT` up to 8
 * without making setup() take noticeable extra time.
 *
 * https://grafana.com/docs/k6/latest/using-k6/test-lifecycle/
 */
export function setup() {
    for (let i = 0; i < 10; i++) {
        http.get(`${CONFIG.baseUrl}${FRONTEND_PATHS[i % FRONTEND_PATHS.length]}`, WARMUP_REQUEST_PARAMS);
    }
}

export default function () {
    for (const path of FRONTEND_PATHS) {
        const res = http.get(`${CONFIG.baseUrl}${path}`, REQUEST_PARAMS);
        check(res, { ...okStatus, ...looksLikeCaminoPage, ...noPHPError, ...noDuplicateAssets });
        sleep(0.5);
    }
}
