import {test, expect} from '@playwright/test';

/**
 * CSP nonce uniqueness — KNOWN-FAILING under FrankenPHP worker mode.
 *
 * TYPO3 generates the CSP nonce inside `RequestId`
 * (cms-core/Classes/Core/RequestId.php), which is constructed exactly
 * once during `Bootstrap::init()` and registered in the DI container as a
 * pre-built service:
 *
 *     RequestId::class => $requestId,   // ContainerBuilder synthetic
 *
 * In worker mode the container survives across requests, so the
 * RequestId — and its `readonly ConsumableNonce $nonce` — is the same
 * object for every request the worker serves. Empirically every response
 * carries the *same* `nonce-…` value until the worker recycles at
 * `MAX_REQUESTS`. This defeats the entire purpose of nonce-based CSP:
 * an attacker who learns the nonce ONCE can inject scripts that bypass
 * CSP for the remaining ~500 requests.
 *
 * Why this is `test.fixme` rather than `test`:
 * `RequestId::$long`, `RequestId::$short`, `RequestId::$microtime`,
 * `RequestId::$nonce`, and `ConsumableNonce::$value` are all `readonly`.
 * PHP enforces readonly even for Closure::bind into the declaring scope
 * (verified on 8.5.6 in this repo). The fix requires either:
 *   (a) reconstructing RequestId via `ReflectionClass::newInstanceWithoutConstructor()`
 *       + first-write Closure::bind for each readonly field, AND walking
 *       every constructor-injected consumer (ContentSecurityPolicyHeaders
 *       middleware, PolicyProvider, ErrorPageController, plus any
 *       transitively-cached ConsumableNonce holder) to rebind their
 *       private `$requestId` field — many surfaces, easy to miss one.
 *   (b) An upstream change to make RequestId per-request (e.g. produced
 *       by a middleware writing to a request attribute instead of a DI
 *       singleton), or to drop `readonly` on RequestId's properties.
 *
 * Leaving the test in place (and skipped) keeps the failure documented
 * for whoever lands one of the fixes above.
 */
test('CSP nonce rotates per request (worker-mode regression)', async ({page}) => {
    const nonces = new Set<string>();
    for (let i = 0; i < 3; i++) {
        const response = await page.goto(`/typo3/main?nonce-probe=${i}`);
        expect(response?.status()).toBe(200);
        const csp = response?.headers()['content-security-policy'] ?? '';
        const match = csp.match(/'nonce-([A-Za-z0-9_-]+)'/);
        expect(match, 'response must include a CSP nonce').not.toBeNull();
        nonces.add(match![1]);
    }
    expect(nonces.size,
        'CSP nonce must change per response; reuse across requests defeats nonce-based CSP'
    ).toBeGreaterThan(1);
});
