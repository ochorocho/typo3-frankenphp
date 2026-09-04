import {test, expect} from '@playwright/test';

/**
 * Regression guard for the nested backend shell pathology.
 *
 * Symptom (user-reported): repeatedly navigating to `/typo3/main`
 * caused the backend chrome to nest inside `list_frame` 2–3 deep,
 * with a stack of "Validating the security token of this form has
 * failed" flashes piled in the session.
 *
 * Root cause (worker-mode only) — two cooperating bugs:
 *
 *   1. `TYPO3\CMS\Core\FormProtection\FormProtectionFactory` cached
 *      `BackendFormProtection` instances in `cache.runtime` keyed only
 *      by type. Under FrankenPHP the runtime cache survives across
 *      requests, so a cached BFP from one session was reused for the
 *      next, validating against the wrong `$sessionToken`.
 *      Fixed upstream-style by the composer-patches patch at
 *      `Patches/cms-core-form-protection-factory-session-aware-cache.patch`,
 *      which folds the BE_USER session identifier into the cache key.
 *
 *   2. `TYPO3\CMS\Backend\Routing\UriBuilder` is a singleton with an
 *      internal `$generated` URI cache keyed by route + parameters +
 *      reference type. The `?token=` value is injected AFTER lookup,
 *      so once a route URI is generated with the first session's
 *      token, the cached URI carries that stale token forever; the
 *      next session's request received the URI with the old token,
 *      validation failed, /typo3/main 302'd to /typo3/login, /login
 *      303'd back to /typo3/main, infinite redirect.
 *      Reset per request in `Classes/Worker/WorkerStateResetter::reset()`.
 */
test('navigating /typo3/main never produces nested list_frame or CSRF flash', async ({page}) => {
    for (let i = 0; i < 5; i++) {
        await page.goto('/typo3/main', {waitUntil: 'load'});
        await page.waitForTimeout(300);
        await expect(page).toHaveURL(/\/typo3\/(module\/[^?]+|main)/);

        const lf = page.locator('iframe[name="list_frame"]');
        const cf = lf.contentFrame();
        if (!cf) continue;

        const innerCount = await cf.locator('iframe').count();
        expect(innerCount, `iteration ${i}: list_frame must not contain another iframe`).toBe(0);

        const flashCount = await page.evaluate(() =>
            Array.from(document.querySelectorAll('.alert-message'))
                .filter(el => el.textContent?.includes('Validating the security token'))
                .length
        );
        expect(flashCount, `iteration ${i}: no real CSRF token flashes`).toBe(0);
    }
});
