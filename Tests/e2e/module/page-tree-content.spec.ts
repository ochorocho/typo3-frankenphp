import {test, expect} from '@playwright/test';

/**
 * Regression: ContentFetcher (cms-backend/View/BackendLayout/ContentFetcher.php)
 * is a `#[Autoconfigure(public: true)]` singleton that caches the full
 * `tt_content` result set under the FIXED key `ContentFetcher_fetchedContentRecords`
 * in the DI-managed `cache.runtime` service — with no page UID in the key. The
 * sibling `BackendLayoutView` does the same for selected layouts. Under PHP-FPM
 * `cache.runtime` is wiped at process death, so this is harmless. Under
 * FrankenPHP worker mode the cache survives across requests; clicking page B in
 * the page tree returns page A's still-cached content. Fixed by flushing
 * `cache.runtime` per request in `WorkerStateResetter::reset()`.
 *
 * This test clicks two pages with disjoint content and asserts the second
 * page's content elements appear AND the first page's do NOT — which is the
 * minimum check that proves cache.runtime is being reset.
 */
test('Web>Layout content elements correspond to the selected page after page-tree clicks', async ({page}) => {
    // Enter via /typo3/ (not /typo3/main): the trailing-slash path runs the
    // full backend bootstrap that renders the page-tree pane for tree-aware
    // modules. /typo3/main can land on a no-tree last-opened module after
    // which a menuitem JS click only swaps the iframe and the tree pane
    // never appears.
    await page.goto('/typo3/');
    // Use the menubar menuitem (not the raw <a data-moduleroute-identifier>)
    // — the menuitem has the in-context JS handler. Clicking the raw <a>
    // triggers TYPO3 14's referrer enforcement and nests list_frame.
    await page.getByRole('menuitem', {name: 'Layout'}).click();

    const listFrame = page.locator('iframe[name="list_frame"]');
    const cf = listFrame.contentFrame();

    // Pin tree locators to .first() — the page-tree component can briefly
    // render two identical treeitems during init (visible + hidden drawer
    // copy) which trips strict mode.
    const treeNode = (id: number) => page.locator(`[role="treeitem"][data-id="${id}"]`).first();

    // Page tree is a Lit web component; treeitems appear once the tree's
    // initial fetchData AJAX resolves. The fetch can occasionally fail on
    // cold boot, leaving the tree element attached but empty (sometimes
    // with a "Navigation loading error" alertdialog). One reload +
    // re-click recovers — same pattern used in docheader-no-duplication.
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

    // Expand the Camino subtree so its children (FAQs, Packing List, etc.)
    // are clickable.
    const caminoNode = treeNode(1);
    if ((await caminoNode.getAttribute('aria-expanded')) !== 'true') {
        await caminoNode.locator('.node-toggle').click();
    }
    await treeNode(5).waitFor({state: 'attached', timeout: 10_000});

    // Click FAQs (uid=5). Wait for the iframe to actually navigate to id=5
    // before asserting content — .click() only dispatches the event; the
    // iframe src flip is the synchronous signal that navigation started.
    await treeNode(5).click();
    await expect(listFrame).toHaveAttribute('src', /[?&]id=5(&|$)/, {timeout: 30_000});
    await expect(cf.getByText("What is the Pilgrim", {exact: false}).first())
        .toBeVisible({timeout: 15_000});

    // Click Camino Route Comparison (uid=7). Its content must appear AND
    // FAQs' must vanish — otherwise the runtime cache leaked.
    await treeNode(7).click();
    await expect(listFrame).toHaveAttribute('src', /[?&]id=7(&|$)/, {timeout: 30_000});
    await expect(cf.getByText('Elena Vásquez', {exact: false}).first())
        .toBeVisible({timeout: 15_000});
    await expect(cf.getByText("What is the Pilgrim", {exact: false}))
        .toHaveCount(0);
});
