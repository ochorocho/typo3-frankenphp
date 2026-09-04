import {test, expect} from '@playwright/test';

/**
 * Same class of regression as page-tree-content.spec.ts but for the Web>List
 * module (data-moduleroute-identifier="records"). ContentFetcher /
 * BackendLayoutView cache keys are page-uid-less so the previous page's
 * record list can leak into the current page's view under worker mode.
 *
 * Fix lives in Classes/Worker/WorkerStateResetter.php (cache.runtime key
 * removal). This test exercises the List rendering path too — if the fix
 * happens to address Layout but not List (e.g. List uses a different cache
 * key we missed), this catches it.
 */
test('Web>List record list corresponds to the selected page after page-tree clicks', async ({page}) => {
    test.setTimeout(60_000);
    await page.goto('/typo3/main');
    await page.locator('#modulemenu a[data-moduleroute-identifier]').first()
        .waitFor({state: 'attached', timeout: 30_000});
    await page.locator('#modulemenu a[data-moduleroute-identifier="records"]').click();

    const cf = page.locator('iframe[name="list_frame"]').contentFrame();
    const listFrame = page.locator('iframe[name="list_frame"]');

    // The page tree component can briefly render two identical treeitems
    // during init (visible tree + hidden drawer copy). Always pin to .first()
    // so getAttribute / click target a single, visible element instead of
    // race-violating strict mode or hitting a detached copy.
    const treeNode = (id: number) => page.locator(`[role="treeitem"][data-id="${id}"]`).first();

    // Tree fetchData AJAX occasionally fails on cold boot (empty tree
    // ± "Navigation loading error" alertdialog); one reload + re-click
    // recovers. Same pattern used in docheader-no-duplication.
    try {
        await treeNode(1).waitFor({state: 'attached', timeout: 30_000});
    } catch {
        const errorAlert = page.getByRole('alertdialog', {name: 'Navigation loading error'});
        if (await errorAlert.isVisible().catch(() => false)) {
            await errorAlert.getByRole('button', {name: 'Close'}).click();
        }
        await page.reload();
        await page.locator('#modulemenu a[data-moduleroute-identifier="records"]').click();
        await treeNode(1).waitFor({state: 'attached', timeout: 20_000});
    }

    const caminoNode = treeNode(1);
    if ((await caminoNode.getAttribute('aria-expanded')) !== 'true') {
        await caminoNode.locator('.node-toggle').click();
    }
    await treeNode(5).waitFor({state: 'attached', timeout: 10_000});

    // Click FAQs (uid=5). Wait for the iframe to actually navigate to id=5
    // before checking content — the click only dispatches an event; the
    // iframe src flip is the synchronous signal that navigation started.
    await treeNode(5).click();
    await expect(listFrame).toHaveAttribute('src', /[?&]id=5(&|$)/, {timeout: 30_000});
    await expect(cf.getByText("What is the Pilgrim", {exact: false}).first())
        .toBeVisible({timeout: 30_000});

    // Click Camino Route Comparison (uid=7) — its records appear AND FAQs's
    // must vanish.
    await treeNode(7).click();
    await expect(listFrame).toHaveAttribute('src', /[?&]id=7(&|$)/, {timeout: 30_000});
    await expect(cf.getByText('Elena Vásquez', {exact: false}).first())
        .toBeVisible({timeout: 30_000});
    await expect(cf.getByText("What is the Pilgrim", {exact: false}))
        .toHaveCount(0);
});
