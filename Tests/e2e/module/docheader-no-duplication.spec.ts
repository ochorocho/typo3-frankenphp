import {test, expect} from '@playwright/test';

/**
 * Regression: under FrankenPHP worker mode, DocHeaderComponent is a shared
 * Symfony-DI singleton and its ButtonBar->buttons[] array accumulates across
 * requests. Every controller call to addButton() appends without anything
 * clearing the array between requests, so by the third backend navigation
 * View / Edit / Cache / Reload / Share buttons appear three times in the
 * doc-header toolbar.
 *
 * Fix lives in Classes/Worker/WorkerStateResetter.php — re-instantiates the
 * ButtonBar from the DocHeader plus clears the shared ButtonBar instance's
 * $buttons via Closure::bind on every worker request.
 *
 * This test navigates into three different modules in sequence and asserts
 * a canonical docheader button ("Clear cache for this page") appears exactly
 * once on the final view — without the reset it would have triplicated.
 */
test('docheader buttons do not duplicate after several page-tree navigations', async ({page}) => {
    test.setTimeout(60_000);
    // Enter via /typo3/ (not /typo3/main): the trailing-slash path goes
    // through the full backend bootstrap that renders the page-tree pane
    // for tree-aware modules. Landing on /typo3/main can pick up a
    // no-tree last-opened module, after which a menuitem JS click only
    // swaps the iframe contents without re-building the outer shell, so
    // the tree pane never appears.
    await page.goto('/typo3/');
    // Use the menubar menuitem (not the raw <a data-moduleroute-identifier>) —
    // the menuitem has the in-context JS handler. Clicking the raw <a>
    // triggers TYPO3 14's referrer enforcement and triply-nests list_frame.
    await page.getByRole('menuitem', {name: 'Layout'}).click();

    const cf = page.locator('iframe[name="list_frame"]').contentFrame();

    // Pin tree locators to .first() — the page-tree component can briefly
    // render two identical treeitems during init (visible + hidden drawer
    // copy) which trips strict mode.
    const treeNode = (id: number) => page.locator(`[role="treeitem"][data-id="${id}"]`).first();

    // The page-tree's fetchData AJAX can occasionally fail on cold boot,
    // leaving the tree element attached but empty (sometimes with a
    // "Navigation loading error" alertdialog). One reload + re-click
    // recovers; this is a fast cold-boot safeguard, not a contention
    // workaround (workers=1 in playwright.config eliminates the latter).
    try {
        await treeNode(1).waitFor({state: 'attached', timeout: 20_000});
    } catch {
        const errorAlert = page.getByRole('alertdialog', {name: 'Navigation loading error'});
        if (await errorAlert.isVisible().catch(() => false)) {
            await errorAlert.getByRole('button', {name: 'Close'}).click();
        }
        await page.reload();
        await page.getByRole('menuitem', {name: 'Layout'}).click();
        await treeNode(1).waitFor({state: 'attached', timeout: 20_000});
    }

    // Expand Camino subtree so child pages are clickable.
    const caminoNode = treeNode(1);
    if ((await caminoNode.getAttribute('aria-expanded')) !== 'true') {
        await caminoNode.locator('.node-toggle').click();
    }
    await treeNode(5).waitFor({state: 'attached', timeout: 10_000});

    // Three page-tree clicks → three Layout-module controller requests →
    // three calls to addButton('Clear cache for this page') on the shared
    // ButtonBar. Without the reset, count would be 3 on the final view.
    for (const id of [1, 5, 7]) {
        await treeNode(id).click();
        await cf.locator('button[title="Clear cache for this page"]').first()
            .waitFor({state: 'visible', timeout: 15_000});
    }

    await expect(cf.locator('button[title="Clear cache for this page"]')).toHaveCount(1);
});
