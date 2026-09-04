/**
 * TYPO3 backend login helper.
 *
 * The login form at `/typo3/login` ships a CSRF token in a hidden
 * <input name="__RequestToken"> field. We GET the form, extract the
 * token, POST credentials, and rely on k6's per-VU cookie jar to carry
 * the resulting `be_typo_user` session cookie into subsequent requests.
 *
 * Note on userident vs p_field: the form has a visible `p_field` and a
 * hidden `userident`. TYPO3's login JS copies `p_field` into `userident`
 * on submit, then submits. Since the BE_USER auth provider receives
 * `userident` plaintext (no client-side hashing in v14), we can post
 * `userident=password` directly and skip the JS dance.
 */

import http from 'k6/http';
import {parseHTML} from 'k6/html';
import {check} from 'k6';
import {CONFIG, REQUEST_PARAMS} from './config.js';
import {noSecurityTokenError} from './checks.js';

/**
 * Backend request params. TYPO3 v14 ships a JS "referrer-refresh"
 * interstitial that wraps every backend route — a browser follows it
 * automatically via inline JS, but k6 doesn't execute JS. Sending an
 * explicit Referer pointing back at the TYPO3 backend tells TYPO3
 * "we came from the backend, you can skip the interstitial".
 */
export const BACKEND_REQUEST_PARAMS = {
    headers: {
        ...REQUEST_PARAMS.headers,
        Referer: `${CONFIG.baseUrl}/typo3/main`,
    },
    timeout: REQUEST_PARAMS.timeout,
    // Inherit the phase=measured tag so backend iteration requests are
    // gated by the phase-scoped thresholds (see lib/thresholds.js).
    tags: REQUEST_PARAMS.tags,
};

/**
 * Log the current VU in as the admin user. Returns true on apparent
 * success, false otherwise. Call once per VU's iteration cycle — or
 * cache the success per-VU via globalThis if you want to amortize the
 * cost across iterations (see `loginOncePerVU` below).
 */
export function login(user = CONFIG.user, pass = CONFIG.pass) {
    const loginUrl = `${CONFIG.baseUrl}/typo3/login`;

    const get = http.get(loginUrl, REQUEST_PARAMS);
    if (get.status !== 200) {
        return false;
    }

    const token = parseHTML(get.body)
        .find('input[name="__RequestToken"]')
        .first()
        .attr('value');

    if (!token) {
        return false;
    }

    const post = http.post(
        loginUrl,
        {
            username: user,
            userident: pass,
            login_status: 'login',
            __RequestToken: token,
        },
        {
            headers: REQUEST_PARAMS.headers,
            // The auth flow returns 303 → /typo3/main?token=… ; we want
            // to follow it so the cookie jar picks up be_typo_user.
            redirects: 5,
            timeout: '30s',
            // Tag with phase=measured so the threshold-scoped
            // http_req_* metrics include the login POST. Without this
            // tag the POST was sitting in the untagged bucket and its
            // failure rate was invisible to the phase-scoped gates
            // (only visible in the global http_req_failed metric).
            tags: REQUEST_PARAMS.tags,
        },
    );

    // A successful login lands somewhere under /typo3/{main,module}/...
    // and never on /typo3/login. Final URL is the most reliable check
    // since k6's redirects handling rewrites response.url to the last
    // landing page.
    const landedAuthenticated =
        post.status === 200
        && typeof post.url === 'string'
        && /\/typo3\/(main|module)/.test(post.url)
        && !/\/typo3\/login/.test(post.url);

    check(post, {
        'login succeeded': () => landedAuthenticated,
        // Surface the POST status code as a check so it shows up in the
        // k6 summary instead of only the http_req_failed rate. When
        // landedAuthenticated is false the test summary now tells us
        // *what* the server replied with (2xx vs 4xx vs 5xx) without
        // needing the trace artifact.
        'login POST returned 2xx or 3xx': (r) => r.status >= 200 && r.status < 400,
        ...noSecurityTokenError,
    });

    return landedAuthenticated;
}

/**
 * Log in only on the first iteration of each VU; subsequent iterations
 * reuse the captured session cookies. Best for backend-heavy scenarios
 * where re-logging-in dominates the total request count.
 *
 * Why we manually re-hydrate the cookie jar each iteration: k6's per-VU
 * cookie jar is reset between iterations (verified empirically — iter 1
 * sees be_typo_user, iter 2 sees an empty jar even though no logout
 * happened). Module-level state, by contrast, IS preserved across
 * iterations of a single VU, so we cache the cookie name/value pairs
 * there and re-set them on the jar at the start of every iteration
 * with an explicit far-future Expires so k6 doesn't drop them again.
 */
let cachedSessionCookies = null;  // module-level → per-VU, survives iterations

function captureCookies() {
    const cookies = http.cookieJar().cookiesForURL(CONFIG.baseUrl);
    const out = [];
    for (const name of Object.keys(cookies)) {
        out.push({name, value: cookies[name][0]});
    }
    return out;
}

function restoreCookies(snapshot) {
    const jar = http.cookieJar();
    for (const {name, value} of snapshot) {
        jar.set(CONFIG.baseUrl, name, value, {
            path: '/',
            expires: 'Fri, 01 Jan 2099 00:00:00 GMT',
        });
    }
}

export function loginOncePerVU(user = CONFIG.user, pass = CONFIG.pass) {
    if (cachedSessionCookies !== null) {
        restoreCookies(cachedSessionCookies);
        return true;
    }
    const ok = login(user, pass);
    if (ok) {
        cachedSessionCookies = captureCookies();
    }
    return ok;
}

/**
 * Backend module routes require a per-session route token (`?token=…`,
 * see TYPO3\CMS\Backend\Http\RouteDispatcher). A tokenless GET on
 * `/typo3/module/...` is redirected to `/typo3/login`, which k6 follows —
 * and a 200 login page passes `okStatus` / `noPHPError` vacuously. Harvest
 * the tokenised link from the module menu of a `/typo3/main` response
 * instead. Returns null when the current user has no access to the module
 * (the link is simply not rendered).
 */
export function moduleUrl(mainBody, routePath, query = '') {
    if (typeof mainBody !== 'string') return null;
    const re = new RegExp('href="([^"]*' + routePath.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&') + '\\?token=[^"]+)"');
    const m = re.exec(mainBody);
    if (!m) return null;
    const href = m[1].replace(/&amp;/g, '&');
    const url = href.startsWith('http') ? href : `${CONFIG.baseUrl}${href}`;
    return query ? `${url}&${query}` : url;
}
