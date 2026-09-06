<?php
/**
 * Dump the resolved cache configuration of the instance at <root>. perf.sh stores the
 * table in data/<label>/caches.txt so a label records which backends it was measured
 * against.
 *
 *   php Tests/perf/probe-caches.php <root>
 */
declare(strict_types=1);

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;

$root = $argv[1] ?? null;
if ($root === null) {
    fwrite(STDERR, "usage: probe-caches.php <root>\n");
    exit(64);
}
chdir($root);
$classLoader = require $root . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
Bootstrap::init($classLoader);

$caches = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];
ksort($caches);
printf("%-30s %-24s %-14s %s\n", 'CACHE', 'BACKEND', 'GROUPS', 'OPTIONS');
foreach ($caches as $name => $config) {
    $backend = (string)($config['backend'] ?? '(default)');
    printf(
        "%-30s %-24s %-14s %s\n",
        $name,
        str_contains($backend, '\\') ? substr(strrchr($backend, '\\'), 1) : $backend,
        implode(',', $config['groups'] ?? ['-']),
        json_encode($config['options'] ?? [])
    );
}
