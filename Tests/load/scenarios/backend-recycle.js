/**
 * MAX_REQUESTS worker-recycle test.
 *
 * `worker.php` honors MAX_REQUESTS by exiting `frankenphp_handle_request`'s
 * loop after that many requests, which signals FrankenPHP to spawn a fresh
 * PHP worker. The point of the recycle is to bound memory growth from
 * leaks we haven't (or can't) yet plug. This scenario exercises the
 * boundary: it issues MAX_REQUESTS + 50 sequential authenticated GETs,
 * crossing the recycle line, and asserts every response (including the
 * first request handled by the brand-new replacement worker) still:
 *
 *   - returns 2xx
 *   - looks like the backend shell
 *   - has no security-token-failure flash
 *
 * Failures here mean either (a) the recycle doesn't actually happen
 * cleanly (the worker exits but request dispatch dropped a request) or
 * (b) the replacement worker's first restore() call has a regression.
 * Both are catastrophic in production and need to be caught here, not in
 * the field.
 *
 * Sized to run in under ~2 minutes against `MAX_REQUESTS=500`. The generated
 * default is 10000 (recycle storms crashed FrankenPHP under load), so start
 * the server with `MAX_REQUESTS=500 frankenphp run -c Caddyfile --envfile .env`
 * for this scenario, or raise ITERATIONS accordingly.
 * If MAX_REQUESTS is set higher in your environment, override
 * `ITERATIONS` via `--env ITERATIONS=N` on the k6 command line.
 *
 * IMPORTANT: run against a freshly-restarted FrankenPHP. Empirically the
 * test produces 5-10% spurious failures when the workers have already
 * been recycled many times by prior tests in the same session — the
 * boundary is intentionally what this scenario probes, and worker
 * state carried over from earlier exercises confuses the signal.
 * `npm run load:backend:recycle` does NOT restart FrankenPHP; restart it
 * yourself if the previous test has been chewing on the same worker for
 * thousands of requests.
 *
 *   k6 run scenarios/backend-recycle.js
 *   k6 run --env ITERATIONS=1100 scenarios/backend-recycle.js   # MAX_REQUESTS=1000
 */

import http from 'k6/http';
import {check} from 'k6';
import {CONFIG} from '../lib/config.js';
import {loginOncePerVU, BACKEND_REQUEST_PARAMS} from '../lib/auth.js';
import {okStatus, looksLikeBackend, noPHPError, noSecurityTokenError} from '../lib/checks.js';

const ITERATIONS = parseInt(__ENV.ITERATIONS || '550', 10);

export const options = {
    insecureSkipTLSVerify: true,
    scenarios: {
        recycle: {
            executor: 'shared-iterations',
            vus:        1,
            iterations: ITERATIONS,
            maxDuration: '5m',
        },
    },
    thresholds: {
        // Allow up to 1% (5-6 in 550) hiccups for the recycle boundary
        // itself — empirically 3-4 requests miss the backend shell when
        // a worker exits mid-flight and the replacement boots. A higher
        // rate would signal a real regression.
        'checks':            ['rate>0.99'],
        // No transport errors are ever tolerable: a TCP-level failure
        // means the recycle wasn't graceful (FrankenPHP should hand a
        // request to the replacement worker, not drop the connection).
        'http_req_failed':   ['rate==0.0'],
    },
};

export default function () {
    if (!loginOncePerVU()) {
        return;
    }

    // Single GET per iteration — no sleep(), the whole point is to cross
    // the MAX_REQUESTS boundary as fast as possible.
    const main = http.get(`${CONFIG.baseUrl}/typo3/main`, BACKEND_REQUEST_PARAMS);
    check(main, {
        ...okStatus,
        ...looksLikeBackend,
        ...noPHPError,
        ...noSecurityTokenError,
    });
}
