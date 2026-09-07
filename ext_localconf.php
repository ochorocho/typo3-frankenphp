<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Development only: the request profiler times every database query through
// a Doctrine driver middleware (Documentation/Profiling.md). Nothing is
// recorded unless the worker saw FRANKENPHP_PROFILE=1.
if (\TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment()) {
    $GLOBALS['TYPO3_CONF_VARS']['DB']['globalDriverMiddlewares']['ochorocho/frankenphp/profile'] = [
        'target' => \Ochorocho\FrankenPhp\Profiler\Database\ProfilingDriverMiddleware::class,
        'after' => ['typo3/core/custom-platform-driver-middleware', 'typo3/core/custom-pdo-driver-result-middleware'],
    ];
}

// FrankenPHP worker: keep the unserialized TypoScript and TSconfig trees of the
// `typoscript` cache in the process, validated by the cache file's mtime
// (Classes/Cache/WorkerPhpFrontend.php). The CLI and per-request PHP keep Core's
// frontend; the memo would not survive there anyway.
if (PHP_SAPI === 'frankenphp') {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typoscript']['frontend'] = \Ochorocho\FrankenPhp\Cache\WorkerPhpFrontend::class;
}
