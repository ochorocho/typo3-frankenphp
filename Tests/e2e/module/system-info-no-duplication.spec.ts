import {test, expect} from '@playwright/test';

/**
 * Regression: SystemInformationToolbarItem is a shared singleton in worker
 * mode and its $systemInformation[] array accumulates on every dropdown
 * render. After several requests the topbar's System Information dropdown
 * shows TYPO3 Version, PHP Version, Database etc. N times instead of once,
 * and the severity badge stays at its highest historical value rather than
 * reflecting current state.
 *
 * Fix lives in Classes/Worker/WorkerStateResetter.php — clears
 * systemInformation/systemMessages/highestSeverity via Closure::bind on
 * every worker request.
 */
test('System Information dropdown does not duplicate entries across navigations', async ({page}) => {
    test.setTimeout(60_000);
    await page.goto('/typo3/main');
    await page.locator('#modulemenu a[data-moduleroute-identifier]').first()
        .waitFor({state: 'attached', timeout: 30_000});

    // Hit the System Information AJAX render endpoint a few times to grow
    // the shared collection if the reset is broken.
    for (const id of ['web_layout', 'records', 'web_layout']) {
        await page.locator(`#modulemenu a[data-moduleroute-identifier="${id}"]`).click();
        await page.waitForTimeout(500);
    }

    // Open the topbar's System Information toolbar item. The button label is
    // "System Information <badge>" (badge is a small count number).
    await page.locator('button').filter({hasText: 'System Information'}).first().click();
    // Allow the dropdown render AJAX to settle.
    await page.waitForTimeout(1000);

    // Each labeled row should appear exactly once. Without the reset they'd
    // appear three or four times after the navigations above.
    await expect(page.getByText('TYPO3 Version', {exact: false})).toHaveCount(1);
    await expect(page.getByText('PHP Version', {exact: false})).toHaveCount(1);
    await expect(page.getByText('Web Server', {exact: false})).toHaveCount(1);
});
