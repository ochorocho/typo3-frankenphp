/**
 * Frontend latency at a fixed request rate.
 *
 * A closed loop (N VUs without think time) reports concurrency divided by
 * throughput, not how fast a page is. This scenario asks the real
 * question: what does a cached page cost while the server handles RATE
 * requests per second? `dropped_iterations` above zero means k6 could not
 * keep the rate, i.e. the server is saturated and the numbers describe
 * queueing, not the page.
 *
 *   k6 run scenarios/frontend-latency.js                       # 200 req/s, 60 s
 *   k6 run --env RATE=400 --env DURATION=120s scenarios/frontend-latency.js
 */

import http from 'k6/http';
import { check } from 'k6';
import { CONFIG, FRONTEND_PATHS, randomFrontendPath, REQUEST_PARAMS, WARMUP_REQUEST_PARAMS } from '../lib/config.js';
import { okStatus, looksLikeCaminoPage, noPHPError, noDuplicateAssets } from '../lib/checks.js';

const RATE = Number(__ENV.RATE || 200);

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        latency: {
            executor:        'constant-arrival-rate',
            rate:            RATE,
            timeUnit:        '1s',
            duration:        __ENV.DURATION || '60s',
            preAllocatedVUs: 50,
            maxVUs:          200,
        },
    },
    // The 10 ms average is the target for a cached page; p95 catches tails.
    thresholds: {
        'http_req_duration{phase:measured}': ['avg<10', 'p(95)<25'],
        'http_req_failed{phase:measured}':   ['rate<0.01'],
        checks:                              ['rate>0.99'],
        dropped_iterations:                  ['count<1'],
    },
};

export function setup() {
    for (let i = 0; i < 10; i++) {
        http.get(`${CONFIG.baseUrl}${FRONTEND_PATHS[i % FRONTEND_PATHS.length]}`, WARMUP_REQUEST_PARAMS);
    }
}

export default function () {
    const res = http.get(`${CONFIG.baseUrl}${randomFrontendPath()}`, REQUEST_PARAMS);
    check(res, { ...okStatus, ...looksLikeCaminoPage, ...noPHPError, ...noDuplicateAssets });
}
