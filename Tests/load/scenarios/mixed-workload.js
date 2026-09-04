/**
 * Mixed workload — 80% anonymous frontend + 20% authenticated backend,
 * 10 VUs × 5 min.
 *
 * k6 supports multiple named scenarios in one run, so this script spins
 * up two co-resident workloads:
 *
 *   - `frontend`:  8 VUs hitting random Camino pages
 *   - `backend`:   2 VUs logged-in admin doing typical nav
 *
 * They share the same FrankenPHP worker pool, so the result reflects a
 * realistic production-ish mix where most traffic is anonymous + cached
 * and a small slice is heavy authenticated work.
 *
 *   k6 run scenarios/mixed-workload.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { CONFIG, randomFrontendPath, REQUEST_PARAMS } from '../lib/config.js';
import { loginOncePerVU, moduleUrl, BACKEND_REQUEST_PARAMS } from '../lib/auth.js';
import { defaultThresholds } from '../lib/thresholds.js';
import {
    okStatus,
    looksLikeCaminoPage,
    looksLikeBackend,
    noPHPError,
    noSecurityTokenError,, looksLikeModule} from '../lib/checks.js';

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        frontend: {
            executor: 'constant-vus',
            vus:      8,
            duration: '5m',
            exec:     'frontend',
        },
        backend: {
            executor: 'constant-vus',
            vus:      2,
            duration: '5m',
            exec:     'backend',
        },
    },
    thresholds: {
        // Tag-scoped thresholds — k6 auto-tags samples by scenario.
        'http_req_duration{scenario:frontend}': ['p(95)<500',  'p(99)<1500'],
        'http_req_duration{scenario:backend}':  ['p(95)<1000', 'p(99)<3000'],
        'http_req_failed':                       ['rate<0.02'],
        'checks':                                ['rate>0.98'],
    },
};

export function frontend() {
    const res = http.get(`${CONFIG.baseUrl}${randomFrontendPath()}`, REQUEST_PARAMS);
    check(res, { ...okStatus, ...looksLikeCaminoPage, ...noPHPError });
    sleep(1);
}

export function backend() {
    if (!loginOncePerVU()) {
        return;
    }
    const main = http.get(`${CONFIG.baseUrl}/typo3/main`, BACKEND_REQUEST_PARAMS);
    check(main, { ...okStatus, ...looksLikeBackend, ...noPHPError, ...noSecurityTokenError });
    sleep(1);

    const layout = http.get(moduleUrl(main.body, '/typo3/module/web/layout', 'id=1'), BACKEND_REQUEST_PARAMS);
    check(layout, { ...okStatus, ...looksLikeModule, ...noPHPError, ...noSecurityTokenError });
    sleep(2);
}
