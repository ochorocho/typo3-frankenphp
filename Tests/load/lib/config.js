/**
 * Shared configuration for all k6 scenarios.
 *
 * Env vars (override at invocation time):
 *   BASE_URL                       default https://localhost:8885
 *   TYPO3_SETUP_ADMIN_USERNAME     default admin
 *   TYPO3_SETUP_ADMIN_PASSWORD     default Password.1
 *
 * The TYPO3_SETUP_ADMIN_* names mirror what `vendor/bin/typo3 setup --help`
 * documents and what `scripts/setup-typo3.sh` exports — one set of envs
 * works for both setup and load testing.
 */

const env = (typeof __ENV !== 'undefined') ? __ENV : {};

export const CONFIG = {
    baseUrl: (env.BASE_URL || 'https://localhost:8885').replace(/\/+$/, ''),
    user:    env.TYPO3_SETUP_ADMIN_USERNAME || 'admin',
    pass:    env.TYPO3_SETUP_ADMIN_PASSWORD || 'Password.1',
    // Non-admin editor seeded by scripts/setup-typo3.sh (file mount on
    // 1:/user_upload/ only). Used by backend-multiuser.js to interleave
    // two permission sets on the same worker pool.
    editorUser: env.TYPO3_E2E_EDITOR_USERNAME || 'editor',
    editorPass: env.TYPO3_E2E_EDITOR_PASSWORD || 'Password.1',
};

// Anonymous frontend routes that exist in the Camino demo site
// (cf. sqlite3 pages table — uid 1,5,6,7,3,4 with the slugs below).
// The site is mounted at /camino/ per Build/config/sites/camino/config.yaml,
// so the URL prefix is required. Tests pick from this set to spread
// load across pages rather than hammer one URL repeatedly.
export const FRONTEND_PATHS = [
    '/camino/',
    '/camino/faqs',
    '/camino/packing-list',
    '/camino/camino-route-comparison',
    '/camino/privacy',
    '/camino/imprint',
];

// Pick a random frontend path — k6 has no built-in PRNG seed, Math.random
// is fine for spreading load.
export function randomFrontendPath() {
    return FRONTEND_PATHS[Math.floor(Math.random() * FRONTEND_PATHS.length)];
}

// Common HTTP request params — TLS skip handles `tls internal` self-signed
// localhost certs, and a User-Agent helps when grepping FrankenPHP access
// logs for load-test traffic.
//
// Every iteration request inherits `tags: { phase: 'measured' }` so that
// phase-scoped thresholds in `lib/thresholds.js` only gate the
// measurement window. setup()/warmup requests use `WARMUP_REQUEST_PARAMS`
// below (tagged phase=warmup) and are deliberately excluded.
export const REQUEST_PARAMS = {
    headers: {
        'User-Agent': 'k6-load-test/typo3-frankenphp',
        'Accept':     'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    },
    timeout: '30s',
    tags:    { phase: 'measured' },
};

// Use for setup()/warmup http.get calls. Caddy's `tls internal` cert
// generation and the first FrankenPHP worker boot can TLS-handshake-fail
// or 5xx on the first few connections to a cold CI runner — those
// samples are noise, not the test subject. Tagging them out keeps the
// strict `http_req_failed{phase:measured} < 0.01` gate honest.
export const WARMUP_REQUEST_PARAMS = {
    ...REQUEST_PARAMS,
    tags: { phase: 'warmup' },
};
