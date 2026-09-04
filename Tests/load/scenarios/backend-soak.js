/**
 * Backend soak test — 5 VUs × 10 min.
 *
 * The single most worker-mode-relevant scenario in the suite. Each
 * iteration hits the backend ~3 times, so over 10 min × 5 VUs ≈ a few
 * thousand authenticated requests, all of which run through
 * WorkerStateResetter::reset(). Any singleton state that fails to
 * reset cleanly per request will accumulate and show up as:
 *
 *   - latency drift across the window (memory pressure / GC),
 *   - rising 4xx/5xx rate near the end (session table corruption,
 *     DocHeader components leaking),
 *   - a check rate that gradually slides below 1.0.
 *
 *   k6 run scenarios/backend-soak.js
 *   k6 run --out json=backend-soak.ndjson scenarios/backend-soak.js
 *
 * For drift analysis, post-process the ndjson per timestamp bucket:
 *   jq -r 'select(.type=="Point" and .metric=="http_req_duration")
 *          | "\(.data.time) \(.data.value)"' backend-soak.ndjson \
 *     | datamash -t' ' groupby 1 mean 2
 * (replace with the bucketing tool of your choice).
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { CONFIG, REQUEST_PARAMS } from '../lib/config.js';
import { loginOncePerVU, moduleUrl, BACKEND_REQUEST_PARAMS } from '../lib/auth.js';
import { backendThresholds } from '../lib/thresholds.js';
import { okStatus, looksLikeBackend, noPHPError, noSecurityTokenError, looksLikeModule} from '../lib/checks.js';

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        soak: {
            executor: 'constant-vus',
            vus:      5,
            duration: '10m',
        },
    },
    thresholds: backendThresholds,
};

export default function () {
    if (!loginOncePerVU()) {
        return;
    }

    const main = http.get(`${CONFIG.baseUrl}/typo3/main`, BACKEND_REQUEST_PARAMS);
    check(main, { ...okStatus, ...looksLikeBackend, ...noPHPError, ...noSecurityTokenError });
    sleep(1);

    // Cycle the page tree so ContentFetcher's cache.runtime keys would
    // accumulate without the reset in WorkerStateResetter. If the
    // singleton reset regresses, this scenario surfaces it as wrong
    // content (and check failures) within minutes.
    for (const id of [1, 5, 6, 7]) {
        const res = http.get(moduleUrl(main.body, '/typo3/module/web/layout', `id=${id}`), BACKEND_REQUEST_PARAMS);
        check(res, { ...okStatus, ...looksLikeModule, ...noPHPError, ...noSecurityTokenError });
        sleep(0.5);
    }
}
