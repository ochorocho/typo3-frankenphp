import {test, expect} from '@playwright/test';

/**
 * The worker resets TYPO3's state in two phases: the structural part runs
 * after `frankenphp_finish_request()` has sent the previous response, only
 * the clock-bound part runs in front of the next request. In Development
 * context the worker reports both through response headers. A "inline"
 * mode on a warm worker means the post-response phase failed and the
 * worker silently fell back to the slow path; a large Reset-Us means the
 * structural reset moved back into the client-visible latency.
 *
 * Skipped outside Development context (the headers exist only there).
 */

const BASE_URL = (process.env.TYPO3_BASE_URL ?? 'https://localhost:8885/').replace(/\/+$/, '');

test('a warm worker resets after the response, not in front of the next request', async ({request}) => {
    // Warm every worker thread: the first request each thread serves is always inline.
    for (let i = 0; i < 20; i++) {
        await request.get(`${BASE_URL}/camino/`);
    }

    const response = await request.get(`${BASE_URL}/camino/`);
    expect(response.status()).toBe(200);
    const headers = response.headers();
    test.skip(headers['x-frankenphp-reset-mode'] === undefined, 'tuning headers are only sent in Development context');

    expect(headers['x-frankenphp-reset-mode']).toBe('post');
    expect(Number(headers['x-frankenphp-discarded']), 'the previous request must have left instances to discard').toBeGreaterThan(0);
    expect(Number(headers['x-frankenphp-post-reset-us']), 'the structural reset must have run after the previous response').toBeGreaterThan(0);
    expect(Number(headers['x-frankenphp-reset-us']), 'only the clock-bound part may run inside the request').toBeLessThan(100);
});
