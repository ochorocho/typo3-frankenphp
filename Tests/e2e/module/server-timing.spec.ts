import {test, expect} from '@playwright/test';

/**
 * The development profiler (Documentation/Profiling.md) adds a Server-Timing
 * header naming the middlewares a request passed through. It exists only in
 * Development containers and records only when the worker was started with
 * FRANKENPHP_PROFILE=1, so the spec skips when the header is absent on a
 * worker that otherwise reports its reset mode.
 */

const BASE_URL = (process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/').replace(/\/+$/, '');

test('Server-Timing lists the frontend middlewares and the query count', async ({request}) => {
    // The sitemap page type carries a query string, so EXT:staticfilecache never serves it as a file.
    const response = await request.get(`${BASE_URL}/camino/?type=1533906435`);
    expect(response.status()).toBe(200);
    const headers = response.headers();
    test.skip(headers['server-timing'] === undefined, 'start the worker with FRANKENPHP_PROFILE=1 to enable the profiler');

    const timing = headers['server-timing'];
    expect(timing).toMatch(/(^|, )total;dur=\d/);
    expect(timing).toMatch(/(^|, )db;dur=[\d.]+;desc="\d+ queries"/);
    expect(timing).toContain('desc="typo3/cms-frontend/prepare-tsfe-rendering"');
    expect(timing).not.toContain('ochorocho/frankenphp/profile');
});

test('Server-Timing on the backend login page names backend middlewares', async ({request}) => {
    const response = await request.get(`${BASE_URL}/typo3/login`);
    const headers = response.headers();
    test.skip(headers['server-timing'] === undefined, 'start the worker with FRANKENPHP_PROFILE=1 to enable the profiler');

    expect(headers['server-timing']).toContain('desc="typo3/cms-backend/');
});
