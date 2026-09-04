/**
 * Backend load test — 5 VUs × 2 min.
 *
 * Each VU logs in once and then loops over a representative backend
 * navigation: /typo3/main → Web>Layout → Web>List → System Information
 * AJAX. 5 VUs is deliberate — concurrent logins against a single
 * SQLite-backed admin user serialize at the session table, so going
 * higher inflates 4xx counts without measuring anything useful.
 *
 * Bump VUs if you've increased FRANKENPHP_WORKER_COUNT and want to
 * measure worker-pool headroom on the authenticated path.
 *
 *   k6 run scenarios/backend-load.js
 */

import http from 'k6/http';
import {check, sleep} from 'k6';
import {CONFIG, REQUEST_PARAMS} from '../lib/config.js';
import {loginOncePerVU, moduleUrl, BACKEND_REQUEST_PARAMS} from '../lib/auth.js';
import {backendThresholds} from '../lib/thresholds.js';
import {okStatus, looksLikeBackend, noPHPError, noSecurityTokenError, looksLikeModule} from '../lib/checks.js';

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        load: {
            executor: 'constant-vus',
            vus: 5,
            duration: '2m',
        },
    },
    thresholds: backendThresholds,
};

export default function () {
    if (!loginOncePerVU()) {
        return;
    }

    const main = http.get(`${CONFIG.baseUrl}/typo3/main`, BACKEND_REQUEST_PARAMS);
    check(main, {...okStatus, ...looksLikeBackend, ...noPHPError, ...noSecurityTokenError});
    sleep(1);

    // Module routes need the per-session route token; harvest it from the
    // module menu (see moduleUrl in lib/auth.js).
    const layout = http.get(moduleUrl(main.body, '/typo3/module/web/layout', 'id=1'), BACKEND_REQUEST_PARAMS);
    check(layout, {...okStatus, ...looksLikeModule, ...noPHPError, ...noSecurityTokenError});
    sleep(1);

    const list = http.get(moduleUrl(main.body, '/typo3/module/records', 'id=1'), BACKEND_REQUEST_PARAMS);
    check(list, {...okStatus, ...looksLikeModule, ...noPHPError, ...noSecurityTokenError});
    sleep(1);
}
