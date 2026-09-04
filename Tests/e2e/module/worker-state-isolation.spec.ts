import {expect, test, type Page} from '@playwright/test';

const BASE_URL = (process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/').replace(/\/+$/, '');
const USER = process.env.TYPO3_SETUP_ADMIN_USERNAME ?? 'admin';
const PASS = process.env.TYPO3_SETUP_ADMIN_PASSWORD ?? 'Password.1';

const SECURITY_TOKEN_RE = /Validating the security token of this form has failed/i;

/**
 * End-to-end regression for cross-session state isolation in worker mode.
 *
 * Until the FormProtectionFactory composer patch + `UriBuilder->$generated`
 * reset + the singleton resets in `Classes/Worker/WorkerStateResetter.php`
 * all landed together, the dev sandbox could not survive a logout +
 * re-login cycle on the same worker: the second login's signed
 * /typo3/main?token=… URL was validated against the previous session's
 * sessionToken and the browser fell into a 302↔303 redirect loop until it
 * gave up with ERR_TOO_MANY_REDIRECTS / "the page isn't redirecting
 * properly". This spec captures that exact scenario so the integrated fix
 * stays integrated.
 *
 * What this spec specifically verifies:
 *
 *   1. A logout + fresh re-login on the same worker arrives at /typo3/main
 *      (i.e. the post-login 303→GET round-trip completes with no
 *      redirect loop).
 *   2. Subsequent navigation through token-bearing backend URLs
 *      (UriBuilder-signed) returns 2xx, not 302→/typo3/login.
 *   3. No "Validating the security token of this form has failed" flash
 *      message appears in the DOM at any point.
 *
 * The spec runs with a fresh `browser.newContext()` per round so cookies
 * from round 1 don't carry into round 2 — the only shared state is
 * server-side (the FrankenPHP worker process + its DI singletons). With
 * the fixes in place this passes deterministically; reverting any of the
 * three pieces (FormProtectionFactory patch, UriBuilder reset, or the
 * audit-added singleton resets) is enough to reintroduce the redirect
 * loop. See `Classes/Worker/WorkerStateResetter.php` for the full
 * inventory of what's reset and why.
 */

async function loginFresh(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/`);
    // Wait for the login form's hidden `__RequestToken` JWT before
    // interacting — same reasoning as in `auth.setup.ts`. The login JS
    // handler that copies `p_field` → `userident` only attaches once
    // the form is parsed; clicking submit too early posts an empty
    // password and the server returns a login-failed page that never
    // redirects to /typo3/main.
    await page.locator('input[name="__RequestToken"]').waitFor({state: 'attached', timeout: 15_000});
    await page.locator('input[name="username"]').fill(USER);
    await page.locator('input[name="p_field"]').fill(PASS);
    await page.locator('#t3-login-submit').click();
    await page.waitForURL(/\/typo3\/(main|module)/, {timeout: 30_000});
    await expect(page).not.toHaveURL(/\/typo3\/login/);
}

async function assertNoSecurityTokenError(page: Page, where: string): Promise<void> {
    const flashCount = await page.evaluate(() =>
        Array.from(document.querySelectorAll('.alert, .alert-message, .callout, .formengine-validation-error'))
            .filter(el => el.textContent ? /Validating the security token/i.test(el.textContent) : false)
            .length,
    );
    expect(flashCount, `${where}: no CSRF token flash in DOM`).toBe(0);
    const bodyText = await page.locator('body').innerText().catch(() => '');
    expect(bodyText, `${where}: body must not contain CSRF error text`).not.toMatch(SECURITY_TOKEN_RE);
}

async function exerciseTokenPaths(page: Page, label: string): Promise<void> {
    // A short tour through backend URLs that each carry a UriBuilder-signed
    // `?token=…`. After the second login this is where cross-session
    // staleness would manifest: any leaked sessionToken or stale cached
    // BFP/URI produces a 302→/typo3/login on the first GET. We assert
    // 2xx by way of "not on /typo3/login" plus no CSRF flash.
    const targets = [
        '/typo3/main',
        '/typo3/module/web/list',
        '/typo3/module/web/info',
        '/typo3/module/site/settings',
    ];
    for (const target of targets) {
        await page.goto(`${BASE_URL}${target}`, {waitUntil: 'load'});
        // Small settle to let deferred iframe/AJAX loads finish — those
        // are the requests most likely to land on a different worker
        // than the parent page under FRANKENPHP_WORKER_COUNT > 1.
        await page.waitForTimeout(400);
        await expect(page, `${label} → ${target}: must not be bounced to /typo3/login`)
            .not.toHaveURL(/\/typo3\/login/);
        await assertNoSecurityTokenError(page, `${label} → ${target}`);
    }
}

// The suite-wide admin storageState (`playwright/.auth/admin.json`) would
// short-circuit the real login flow — and the real login flow is exactly
// what this spec is testing. Force a cookie-less context.
test.use({storageState: {cookies: [], origins: []}});

test('logout then fresh re-login does not leak the previous session\'s state', async ({browser}) => {
    test.setTimeout(120_000);

    // === Round 1 ===
    // First fresh login on a possibly-already-warm worker. This is the
    // "easy" half — auth.setup.ts has been doing this for a while.
    const contextA = await browser.newContext({ignoreHTTPSErrors: true});
    const pageA = await contextA.newPage();
    await loginFresh(pageA);
    await exerciseTokenPaths(pageA, 'round 1');

    // Logout. The server-side session row is destroyed; the
    // BackendFormProtection.clean() call inside logoff() also removes
    // the formProtectionSessionToken from sys_registry. We have to
    // resolve the tokenized logout URL via the rendered UI — naked
    // `/typo3/logout` is a backend route that requires the per-session
    // `?token=…` (UriBuilder-signed), so navigating without it
    // 302s straight back to /typo3/main.
    await pageA.goto(`${BASE_URL}/typo3/main`);
    const logoutHref = await pageA.locator('a[href*="/typo3/logout"]').first().getAttribute('href');
    expect(logoutHref, 'logout link with tokenized href must exist in the topbar').toBeTruthy();
    await pageA.goto(`${BASE_URL}${logoutHref}`);
    await pageA.waitForURL(/\/typo3\/login/, {timeout: 15_000});
    await contextA.close();

    // === Round 2 ===
    // The hard half: a brand-new browser context (no cookies from
    // round 1) logs in fresh on the SAME long-running FrankenPHP
    // worker. Before the fixes, round 2's POST /typo3/login signed
    // its 303 URL with one session's token while the cached
    // UriBuilder URI for /typo3/main still carried the previous
    // session's token → mismatch → 302 → /typo3/login → 303 →
    // /typo3/main → ERR_TOO_MANY_REDIRECTS.
    const contextB = await browser.newContext({ignoreHTTPSErrors: true});
    const pageB = await contextB.newPage();
    await loginFresh(pageB);
    await assertNoSecurityTokenError(pageB, 'round 2: post-login');
    await exerciseTokenPaths(pageB, 'round 2');

    // === Round 3 ===
    // Hammer the same paths a few more times to make sure stale state
    // isn't just being masked by happy-path worker dispatch. With
    // FRANKENPHP_WORKER_COUNT > 1 (the sandbox default) and these
    // repeated navigations, any residual cross-session contamination
    // gets multiple chances to surface as a flash or a redirect bounce.
    for (let i = 0; i < 3; i++) {
        await exerciseTokenPaths(pageB, `round 3 iteration ${i}`);
    }

    await contextB.close();
});
