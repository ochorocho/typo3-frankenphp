import {test, expect} from '@playwright/test';

// Regression test for the worker-mode MenuRegistry leak:
// visiting a backend module that registers a DocHeader MenuRegistry dropdown
// left the dropdown in the SHARED singleton, so subsequent modules emitted
// a ghost dropdown beside their own. Fixed in WorkerStateResetter by
// clearing MenuRegistry's $menus[] via the DocHeader getter on every
// worker request.
//
// Seeder: System > Configuration (lowlevel). It always renders its
// "Configuration to show: …" dropdown (≥2 items unconditionally, no
// tree dependency). Extension Manager was the original seeder but its
// menu only renders with ≥2 items — two of its four are conditional and
// drop out in composer-managed installs, so the seed silently fails.
test('Module action dropdown does not leak across module switches', async ({page}) => {
    // Direct nav to /typo3/module/<id> triggers TYPO3 14's referrer
    // enforcement and produces a nested/wrong backend shell — navigate via
    // the module menu instead, which stays in the standard list_frame
    // layout and actually loads the requested module.
    await page.goto('/typo3/main');
    const listFrame = page.locator('iframe[name="list_frame"]');
    const cf = listFrame.contentFrame();

    // Seed the shared MenuRegistry by opening System > Configuration. Wait
    // for the iframe src to flip (synchronous signal that the module was
    // requested) and for the "Configuration to show:" dropdown to render
    // (signal that lowlevel's MenuRegistry entry was actually registered
    // — i.e., the seed worked).
    await page.getByRole('menuitem', {name: 'Configuration'}).click();
    await expect(listFrame).toHaveAttribute('src', /\/module\/system[/_]config/, {timeout: 30_000});
    await expect(cf.getByRole('button', {name: /^Configuration to show:/}))
        .toBeVisible({timeout: 30_000});

    // Switch to Page TSconfig — a different module that registers its own
    // "Module action:" MenuRegistry dropdown. With the leak in place,
    // lowlevel's "Configuration to show:" dropdown is still in the
    // registry and gets emitted alongside PTC's own. With the fix, only
    // PTC's own dropdown is present.
    await page.getByRole('menuitem', {name: 'Page TSconfig'}).click();
    await expect(listFrame).toHaveAttribute('src', /pagetsconfig/, {timeout: 30_000});

    await expect(cf.getByRole('button', {name: /^Module action:/})).toHaveCount(1);
    await expect(cf.getByRole('button', {name: /^Configuration to show:/}))
        .toHaveCount(0);
});
