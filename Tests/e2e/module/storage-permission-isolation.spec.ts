import {test, expect, type Frame, type Page} from '@playwright/test';
import path from 'node:path';

/**
 * ResourceStorage objects carry PER-USER state: `StoragePermissionsAspect`
 * writes the current backend user's file permissions and the
 * `evaluatePermissions` flag onto the storage when it is first created.
 * `StorageRepository` caches storage objects. In a worker that cache used
 * to survive across requests, so the second user to touch a storage on a
 * worker inherited the first user's permissions: an editor whose only
 * file mount is `1:/user_upload/` could list the storage root after an
 * admin had used the same worker (admin storages skip permission
 * evaluation entirely), and vice versa.
 *
 * Fixture (seeded by scripts/setup-typo3.sh): backend user `editor` /
 * `Password.1`, non-admin, in group "e2e editors" whose only file mount is
 * `1:/user_upload/` with read permissions and the File > Filelist module.
 *
 * The test alternates admin and editor requests many times. With
 * FRANKENPHP_WORKER_COUNT=2 (the dev default) a leaky implementation fails
 * within the first few rounds; with a single worker it fails on the second.
 *
 * Module URLs are opened as top-level navigations: TYPO3 wraps them into
 * /typo3/main (which mints the route token) and loads the module in the
 * `list_frame` iframe, so the assertions read the iframe's text.
 */

const BASE_URL = (process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/').replace(/\/+$/, '');
const EDITOR_USER = process.env.TYPO3_E2E_EDITOR_USERNAME ?? 'editor';
const EDITOR_PASS = process.env.TYPO3_E2E_EDITOR_PASSWORD ?? 'Password.1';
const ROUNDS = 8;
const ADMIN_STORAGE = path.resolve(__dirname, '..', '..', 'playwright', '.auth', 'admin.json');

const ROOT_LISTING = `${BASE_URL}/typo3/module/file/list?id=${encodeURIComponent('1:/')}`;
const MOUNT_LISTING = `${BASE_URL}/typo3/module/file/list?id=${encodeURIComponent('1:/user_upload/')}`;
const BLOCKED_RE = /Folder not accessible|not allowed to access|missing.*permission/i;

async function loginAs(page: Page, user: string, pass: string): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/`);
    await page.locator('input[name="__RequestToken"]').waitFor({state: 'attached', timeout: 15_000});
    await page.locator('input[name="username"]').fill(user);
    await page.locator('input[name="p_field"]').fill(pass);
    await page.locator('#t3-login-submit').click();
    await page.waitForURL(/\/typo3\/(main|module)/, {timeout: 30_000});
    await expect(page).not.toHaveURL(/\/typo3\/login/);
}

async function openModuleText(page: Page, url: string, label: string): Promise<string> {
    await page.goto(url);
    await expect(page, `${label}: must not be bounced to /typo3/login`).not.toHaveURL(/\/typo3\/login/);
    await page.locator('iframe[name="list_frame"]').waitFor({state: 'attached', timeout: 15_000});
    const frame = page.frame({name: 'list_frame'}) as Frame;
    await frame.waitForURL(/module\/file\/list/, {timeout: 15_000});
    await frame.locator('.module, .alert').first().waitFor({timeout: 15_000});
    return frame.locator('body').innerText();
}

test('editor never inherits admin storage permissions on a shared worker', async ({browser}) => {
    const adminContext = await browser.newContext({storageState: ADMIN_STORAGE});
    const editorContext = await browser.newContext({storageState: {cookies: [], origins: []}});
    try {
        const adminPage = await adminContext.newPage();
        const editorPage = await editorContext.newPage();
        await loginAs(editorPage, EDITOR_USER, EDITOR_PASS);

        for (let round = 1; round <= ROUNDS; round++) {
            const adminRoot = await openModuleText(adminPage, ROOT_LISTING, `round ${round} admin root`);
            expect(adminRoot, `round ${round}: admin must see the storage root`).toContain('user_upload');
            expect(adminRoot, `round ${round}: admin must not be blocked by editor permissions`).not.toMatch(BLOCKED_RE);

            const editorRoot = await openModuleText(editorPage, ROOT_LISTING, `round ${round} editor root`);
            expect(editorRoot, `round ${round}: editor must be blocked outside the file mount`).toMatch(BLOCKED_RE);
            expect(editorRoot, `round ${round}: editor must not see root-level folders`).not.toContain('_temp_');

            const editorMount = await openModuleText(editorPage, MOUNT_LISTING, `round ${round} editor mount`);
            expect(editorMount, `round ${round}: editor must still reach the own file mount`).not.toMatch(BLOCKED_RE);
        }
    } finally {
        await adminContext.close();
        await editorContext.close();
    }
});
