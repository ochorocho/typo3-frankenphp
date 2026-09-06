<?php
/**
 * Flush the named TYPO3 caches of the instance at <root> through its CacheManager, so the
 * flush works for any backend (the TAE original spoke to Redis directly).
 *
 *   php Tests/perf/perf-flush.php <root> pages,hash
 *
 * perf.sh drops exactly `pages` and `hash` for a page cache miss: `typoscript` is a file
 * backend that survives a real miss, and `rootline` is only invalidated when the page
 * tree changes, so a page falling out of the cache does not lose it.
 */
declare(strict_types=1);

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

[, $root, $caches] = $argv + [null, null, null];
if ($root === null || $caches === null) {
    fwrite(STDERR, "usage: perf-flush.php <root> <cache,cache,...>\n");
    exit(64);
}
chdir($root);
$classLoader = require $root . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
Bootstrap::init($classLoader);

$cacheManager = GeneralUtility::makeInstance(CacheManager::class);
foreach (explode(',', $caches) as $name) {
    $cacheManager->getCache(trim($name))->flush();
}
