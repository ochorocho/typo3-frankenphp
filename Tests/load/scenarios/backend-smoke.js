/**
 * Backend smoke test — 1 VU × 30 s.
 *
 * Validates the authenticated path end-to-end: log in once, then hit
 * /typo3/main and Web>Layout in a loop. Catches "login regression",
 * "backend bootstrap crash", and "WorkerStateResetter fails on its
 * first reset" before spending minutes on heavier scenarios.
 *
 *   k6 run scenarios/backend-smoke.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { CONFIG, REQUEST_PARAMS } from '../lib/config.js';
import { loginOncePerVU, BACKEND_REQUEST_PARAMS } from '../lib/auth.js';
import { backendThresholds } from '../lib/thresholds.js';
import { okStatus, looksLikeBackend, noPHPError, noSecurityTokenError } from '../lib/checks.js';

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        smoke: {
            executor: 'constant-vus',
            vus:      1,
            duration: '30s',
        },
    },
    thresholds: backendThresholds,
};

export default function () {
    if (!loginOncePerVU()) {
        return;  // login failed, skip iteration
    }

    const main = http.get(`${CONFIG.baseUrl}/typo3/main`, BACKEND_REQUEST_PARAMS);
    check(main, { ...okStatus, ...looksLikeBackend, ...noPHPError, ...noSecurityTokenError });
    sleep(1);

    const layout = http.get(`${CONFIG.baseUrl}/typo3/module/web/layout?id=1`, BACKEND_REQUEST_PARAMS);
    check(layout, { ...okStatus, ...noPHPError, ...noSecurityTokenError });
    sleep(1);
}
