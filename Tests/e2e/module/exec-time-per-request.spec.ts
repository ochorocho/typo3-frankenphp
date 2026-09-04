import {test, expect, type Page} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

/**
 * `$GLOBALS['EXEC_TIME']` is set once per process by
 * SystemEnvironmentBuilder. In a worker that value would freeze at boot,
 * and everything Core stamps with EXEC_TIME (record tstamps, session
 * timestamps, `be_users.lastlogin`) would carry the boot time forever.
 * WorkerStateResetter refreshes EXEC_TIME / ACCESS_TIME / SIM_* per request.
 *
 * Probe: log the editor fixture in twice, a few seconds apart, in fresh
 * browser contexts. BackendUserAuthentication writes `lastlogin = EXEC_TIME`
 * on every successful login, so the second value must be later than the
 * first. With a frozen EXEC_TIME both logins would record the same value.
 *
 * Reads the sandbox SQLite database directly (sqlite3 CLI, the same tool
 * scripts/setup-typo3.sh requires). Skips when neither the CLI nor the
 * database is available, e.g. against a remote TYPO3_BASE_URL.
 */

const BASE_URL = (process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/').replace(/\/+$/, '');
const EDITOR_USER = process.env.TYPO3_E2E_EDITOR_USERNAME ?? 'editor';
const EDITOR_PASS = process.env.TYPO3_E2E_EDITOR_PASSWORD ?? 'Password.1';
const GAP_SECONDS = 3;

function sqliteDatabase(): string | null {
    if (process.env.TYPO3_SQLITE_DB) {
        return fs.existsSync(process.env.TYPO3_SQLITE_DB) ? process.env.TYPO3_SQLITE_DB : null;
    }
    const dir = path.resolve(__dirname, '..', '..', '..', 'Build', 'var', 'sqlite');
    if (!fs.existsSync(dir)) {
        return null;
    }
    const file = fs.readdirSync(dir).find(name => name.endsWith('.sqlite'));
    return file ? path.join(dir, file) : null;
}

function lastLogin(db: string, username: string): number {
    const out = execFileSync('sqlite3', [db, `SELECT lastlogin FROM be_users WHERE username = '${username}';`], {encoding: 'utf8'});
    return Number.parseInt(out.trim(), 10);
}

async function loginAs(page: Page, user: string, pass: string): Promise<void> {
    await page.goto(`${BASE_URL}/typo3/`);
    await page.locator('input[name="__RequestToken"]').waitFor({state: 'attached', timeout: 15_000});
    await page.locator('input[name="username"]').fill(user);
    await page.locator('input[name="p_field"]').fill(pass);
    await page.locator('#t3-login-submit').click();
    await page.waitForURL(/\/typo3\/(main|module)/, {timeout: 30_000});
    await expect(page).not.toHaveURL(/\/typo3\/login/);
}

test('EXEC_TIME advances between requests (lastlogin of two logins differs)', async ({browser}) => {
    const db = sqliteDatabase();
    let sqliteAvailable = db !== null;
    try {
        execFileSync('sqlite3', ['-version'], {stdio: 'ignore'});
    } catch {
        sqliteAvailable = false;
    }
    test.skip(!sqliteAvailable, 'needs the sqlite3 CLI and the sandbox database (set TYPO3_SQLITE_DB)');

    const first = await browser.newContext({storageState: {cookies: [], origins: []}});
    const firstPage = await first.newPage();
    await loginAs(firstPage, EDITOR_USER, EDITOR_PASS);
    await first.close();
    const firstLogin = lastLogin(db as string, EDITOR_USER);
    expect(firstLogin, 'first login must record a timestamp').toBeGreaterThan(0);

    await new Promise(resolve => setTimeout(resolve, GAP_SECONDS * 1000));

    const second = await browser.newContext({storageState: {cookies: [], origins: []}});
    const secondPage = await second.newPage();
    await loginAs(secondPage, EDITOR_USER, EDITOR_PASS);
    await second.close();
    const secondLogin = lastLogin(db as string, EDITOR_USER);

    expect(secondLogin - firstLogin, 'lastlogin must advance with wall-clock time, not freeze at worker boot')
        .toBeGreaterThanOrEqual(GAP_SECONDS - 1);
    const now = Math.floor(Date.now() / 1000);
    expect(now - secondLogin, 'lastlogin must be the current time, not the worker boot time').toBeLessThan(60);
});
