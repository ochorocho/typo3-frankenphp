import { defineConfig, devices } from '@playwright/test';

/**
 * Read environment variables from file.
 * https://github.com/motdotla/dotenv
 */
// import dotenv from 'dotenv';
// import path from 'path';
// dotenv.config({ path: path.resolve(__dirname, '.env') });

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: './e2e',
  /* Tests within the same file run serially (each file is one TYPO3 backend
   * "user session" doing heavy iframe navigation). Files across projects
   * still parallelize via `workers` below. */
  fullyParallel: false,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry up to 2× both locally and on CI. With workers > 1 the dev
   * sandbox sees concurrent TYPO3 session mutation across browser
   * projects (chromium / firefox / webkit all reuse the same admin
   * storageState, so they share one server-side session and clobber each
   * other's UC for "last selected sub-view" in modules like Page
   * TSconfig). 2 retries gets the flaky tests across without masking
   * deterministic breakage. */
  retries: 1,
  /* Serial execution — all specs share the same admin storageState
   * (auth.setup.ts), so any two concurrent specs hit the same TYPO3
   * backend session and the same UC table. Under that contention the
   * backend produces stale CSRF tokens and bounces the iframe through
   * /typo3/main repeatedly (visibly: quadruply-nested shell with
   * "Validating the security token of this form has failed" banners in
   * the leaf, or an empty page-tree with "Navigation loading error").
   * workers=1 measured faster than workers=2 on this sandbox (~31 s vs
   * 35–42 s) because the contention overhead exceeds the parallelism
   * gain. Override with `--workers=N` if you've provisioned per-spec
   * sessions or scaled up the backend. */
  workers: 3,
  /* Per-assertion timeout. Default 5 s is too tight under concurrent
   * iframe-heavy load — backend navigations queue at FrankenPHP's
   * 2-worker pool. 15 s eliminates spurious "element not found" flakes
   * without masking real bugs. */
  expect: {timeout: 15_000},
  /* Per-test overall timeout. Default 30 s is also too tight under
   * workers > 1: each iframe-heavy spec does many sequential nav steps
   * and the queue depth grows with concurrency. 90 s gives the slowest
   * specs (backend-smoke iterates every module) headroom; genuinely
   * hung tests still fail loudly. */
  timeout: 90_000,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: 'html',
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    baseURL: process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/',
    // The dev server uses Caddy's `tls internal` (self-signed cert).
    ignoreHTTPSErrors: true,

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'on-first-retry',
    /* Capture a screenshot on failure (visible in the HTML report next to
     * the failing assertion). `only-on-failure` keeps the artifact small
     * on green runs while still giving us a frame to diagnose breakage. */
    screenshot: 'only-on-failure',
  },

  /* Configure projects for major browsers */
  projects: [
    // Logs in once and persists session state to playwright/.auth/admin.json.
    // Browser projects below depend on this, so each test starts authenticated.
    // Concurrent logins against the dev sandbox (single admin user, 2-worker
    // FrankenPHP, SQLite session table) are unreliable — keep this serial.
    {
      name: 'setup',
      testMatch: /.*\.setup\.ts/,
      // Without a device the project defaults to chromium, which CI does not install.
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'], storageState: 'playwright/.auth/admin.json' },
      dependencies: ['setup'],
    },

    /* Test against mobile viewports. */
    // {
    //   name: 'Mobile Chrome',
    //   use: { ...devices['Pixel 5'] },
    // },
    // {
    //   name: 'Mobile Safari',
    //   use: { ...devices['iPhone 12'] },
    // },

    /* Test against branded browsers. */
    // {
    //   name: 'Microsoft Edge',
    //   use: { ...devices['Desktop Edge'], channel: 'msedge' },
    // },
    // {
    //   name: 'Google Chrome',
    //   use: { ...devices['Desktop Chrome'], channel: 'chrome' },
    // },
  ],

  /* Run your local dev server before starting the tests */
  // webServer: {
  //   command: 'npm run start',
  //   url: 'http://localhost:3000',
  //   reuseExistingServer: !process.env.CI,
  // },
});
