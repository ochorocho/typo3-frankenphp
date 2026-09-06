<?php
/**
 * HTTP flush endpoint for caches a CLI process cannot reach.
 *
 * APCu is one shared-memory segment per process: a `typo3 cache:flush` from the
 * command line empties the CLI's own segment and leaves the server's untouched. This
 * script runs inside the FrankenPHP process (per-request thread, same segment as the
 * workers) and flushes the caches named in ?__perf_flush=pages,hash.
 *
 * Sandbox only. Install: copy to Build/public/perf-flush.php and add to the Caddyfile,
 * before the `route` block:
 *
 *   @perf_flush query __perf_flush=*
 *   handle @perf_flush {
 *       rewrite * /perf-flush.php
 *       php
 *   }
 *
 * then run the harness with PERF_FLUSH_URL="https://localhost:8885/?__perf_flush=".
 * Loopback clients only.
 */
declare(strict_types=1);

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('loopback only');
}
$caches = array_filter(array_map('trim', explode(',', (string)($_GET['__perf_flush'] ?? ''))));
if ($caches === []) {
    http_response_code(400);
    exit('usage: ?__perf_flush=pages,hash');
}

$classLoader = require dirname(__DIR__) . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
Bootstrap::init($classLoader);

$cacheManager = GeneralUtility::makeInstance(CacheManager::class);
foreach ($caches as $name) {
    $cacheManager->getCache($name)->flush();
}
header('Content-Type: text/plain');
echo 'flushed ' . implode(',', $caches) . "\n";
