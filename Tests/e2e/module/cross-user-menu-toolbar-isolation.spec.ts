import {test, expect, type Page} from '@playwright/test';
import path from 'node:path';

/**
 * Cross-user isolation of the backend shell on a shared worker pool.
 *
 * Two Core registries used to leak the FIRST user's view to every later
 * user on the same worker process:
 *
 *  - `ModuleProvider::filterInaccessibleSubModules()` removes submodules
 *    from the module objects owned by the shared `ModuleRegistry`. After a
 *    restricted editor, an admin saw a two-entry module menu.
 *  - `ToolbarItemsRegistry` holds toolbar item instances whose constructors
 *    compute per-user data (`ClearCacheToolbarItem::$cacheActions`). The
 *    editor's item (no cache actions) crashed the admin's topbar.
 *
 * Both are discarded per request now (see Classes/Worker/KeepList.php).
 * This spec alternates an admin and the restricted `editor` fixture and
 * asserts role-specific markers of the backend shell every round:
 *
 *  - admin: System module in the menu, clear-cache dropdown in the toolbar
 *  - editor: no System / Admin Tools modules, no clear-cache dropdown,
 *    Media module present, no error page
 *
 * Also asserts that a flash message the editor triggered ("Folder not
 * accessible") never shows up on the admin's next page (FlashMessageService
 * is a per-request service; a leak would surface exactly here).
 *
 * Fixture: backend user `editor` / `Password.1` seeded by scripts/setup-typo3.sh.
 */

const BASE_URL = (process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/').replace(/\/+$/, '');
const EDITOR_USER = process.env.TYPO3_E2E_EDITOR_USERNAME ?? 'editor';
const EDITOR_PASS = process.env.TYPO3_E2E_EDITOR_PASSWORD ?? 'Password.1';
const ROUNDS = 6;
const ADMIN_STORAGE = path.resolve(__dirname, '..', '..', 'playwright', '.auth', 'admin.json');

const SYSTEM_MODULE = 'data-modulemenu-identifier="system"';
const ADMIN_MODULE = 'data-modulemenu-identifier="admin"';
// A main module with a single accessible submodule is promoted to a
// standalone entry, so the editor's Media module renders as
// `media_management`, the admin's as the `media` group.
const MEDIA_MODULE_RE = /data-modulemenu-identifier="media(_management)?"/;
const CLEAR_CACHE_ACTION = 't3js-toolbar-cache-flush-action';
const ERROR_PAGE_RE = /Whoops, looks like something went wrong|Oops, an error occurred/i;
const EDITOR_FLASH_RE = /Folder not accessible/i;

async function loginAs(page: Page, user: string, pass: string): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/`);
    await page.locator('input[name="__RequestToken"]').waitFor({state: 'attached', timeout: 15_000});
    await page.locator('input[name="username"]').fill(user);
    await page.locator('input[name="p_field"]').fill(pass);
    await page.locator('#t3-login-submit').click();
    await page.waitForURL(/\/typo3\/(main|module)/, {timeout: 30_000});
    await expect(page).not.toHaveURL(/\/typo3\/login/);
}

async function shellHtml(page: Page, url: string, label: string): Promise<string> {
    await page.goto(url);
    await expect(page, `${label}: must not be bounced to /typo3/login`).not.toHaveURL(/\/typo3\/login/);
    await page.locator('[data-modulemenu-identifier]').first().waitFor({state: 'attached', timeout: 15_000});
    return page.content();
}

test('module menu and toolbar never leak between admin and editor', async ({browser}) => {
    const adminContext = await browser.newContext({storageState: ADMIN_STORAGE});
    const editorContext = await browser.newContext({storageState: {cookies: [], origins: []}});
    try {
        const adminPage = await adminContext.newPage();
        const editorPage = await editorContext.newPage();
        await loginAs(editorPage, EDITOR_USER, EDITOR_PASS);

        for (let round = 1; round <= ROUNDS; round++) {
            // Editor first: a leaky worker would hand the reduced menu / the
            // editor's toolbar items to the admin request that follows.
            const editorShell = await shellHtml(editorPage, `${BASE_URL}/typo3/main`, `round ${round} editor`);
            expect(editorShell, `round ${round}: editor page must not be an error page`).not.toMatch(ERROR_PAGE_RE);
            expect(editorShell, `round ${round}: editor must see the Media module`).toMatch(MEDIA_MODULE_RE);
            expect(editorShell, `round ${round}: editor must not see the System module`).not.toContain(SYSTEM_MODULE);
            expect(editorShell, `round ${round}: editor must not see Admin Tools`).not.toContain(ADMIN_MODULE);
            expect(editorShell, `round ${round}: editor must not get the clear-cache dropdown`).not.toContain(CLEAR_CACHE_ACTION);

            // Trigger a flash message in the editor's session (root folder is
            // outside the editor's file mount).
            const blocked = `${BASE_URL}/typo3/module/file/list?id=${encodeURIComponent('1:/')}`;
            await editorPage.goto(blocked);
            await editorPage.waitForLoadState('load');

            const adminShell = await shellHtml(adminPage, `${BASE_URL}/typo3/main`, `round ${round} admin`);
            expect(adminShell, `round ${round}: admin page must not be an error page`).not.toMatch(ERROR_PAGE_RE);
            expect(adminShell, `round ${round}: admin must keep the System module`).toContain(SYSTEM_MODULE);
            expect(adminShell, `round ${round}: admin must keep Admin Tools`).toContain(ADMIN_MODULE);
            expect(adminShell, `round ${round}: admin must keep the clear-cache dropdown`).toContain(CLEAR_CACHE_ACTION);
            expect(adminShell, `round ${round}: editor's flash must not leak to the admin`).not.toMatch(EDITOR_FLASH_RE);
        }
    } finally {
        await adminContext.close();
        await editorContext.close();
    }
});
