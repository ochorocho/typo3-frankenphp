import {expect, test, type Page} from '@playwright/test';

const BASE_URL = (process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/').replace(/\/+$/, '');
const USER = process.env.TYPO3_SETUP_ADMIN_USERNAME ?? 'admin';
const PASS = process.env.TYPO3_SETUP_ADMIN_PASSWORD ?? 'Password.1';

const SECURITY_TOKEN_RE = /Validating the security token of this form has failed/i;

/**
 * Regression for the `Registry` in-memory cache leak.
 *
 * `BackendFormProtection` reads/writes `formProtectionSessionToken:<UID>`
 * through `Registry`. `Registry` is a SingletonInterface that Symfony DI
 * shares across worker requests — so its in-memory `$entries['core']`
 * cache holds the previous session's token past a re-login, and every
 * form submit in the new session fails "Validating the security token
 * of this form has failed".
 *
 * The complementary `worker-state-isolation` spec covers cross-CLIENT
 * isolation (two distinct browser contexts against the same worker).
 * This spec covers SAME-CLIENT re-login on the same worker — the
 * scenario Playwright previously missed, which is why the regression
 * shipped.
 */
async function loginFresh(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/`);
    await page.locator('input[name="__RequestToken"]').waitFor({state: 'attached', timeout: 15_000});
    await page.locator('input[name="username"]').fill(USER);
    await page.locator('input[name="p_field"]').fill(PASS);
    await page.locator('#t3-login-submit').click();
    await page.waitForURL(/\/typo3\/(main|module)/, {timeout: 30_000});
    await expect(page).not.toHaveURL(/\/typo3\/login/);
}

// The suite-wide admin storageState (`playwright/.auth/admin.json`) would
// short-circuit the real login flow — and the real login flow is exactly
// what this spec is testing. Force a cookie-less context.
test.use({storageState: {cookies: [], origins: []}});

test('same-client re-login does not leak Registry-backed session token', async ({browser}) => {
    // Use a fresh context (no shared storageState) so this test drives
    // the full login → logout → login cycle rather than reusing the
    // pre-baked admin session.
    const context = await browser.newContext({ignoreHTTPSErrors: true, storageState: {cookies: [], origins: []}});
    const page = await context.newPage();

    try {
        // Round 1 — log in, navigate.
        await loginFresh(page);
        await page.goto(`${BASE_URL}/typo3/main`);
        await expect(page.locator('body')).not.toContainText(SECURITY_TOKEN_RE);

        // Log out via the officially wired /typo3/logout endpoint.
        // The BFP calls `persistSessionToken()` on session destruction,
        // which rotates the token in `sys_registry`.
        const logoutHref = await page.locator('a[href*="/typo3/logout"]').first().getAttribute('href');
        expect(logoutHref, 'logout link exists in the backend chrome').toBeTruthy();
        await page.goto(`${BASE_URL}${logoutHref}`);
        await page.waitForURL(/\/typo3\/login/, {timeout: 15_000});

        // Round 2 — same client, log back in, navigate again.
        await loginFresh(page);
        await page.goto(`${BASE_URL}/typo3/main`);

        // The BFP-token banner would appear as a flash-style callout.
        // Assert on the whole document to catch any placement.
        await expect(page.locator('body')).not.toContainText(SECURITY_TOKEN_RE);
    } finally {
        await context.close();
    }
});
