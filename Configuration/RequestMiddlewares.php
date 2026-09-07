<?php

declare(strict_types=1);

$middlewares = [
    'backend' => [
        // Worker-mode workaround: TYPO3's response emitter wipes PHP-native
        // Set-Cookie headers under FrankenPHP. This middleware re-injects
        // them into the PSR-7 response on the way out. See the class
        // docblock for the full trace.
        'ochorocho/frankenphp/preserve-native-session-cookies' => [
            'target' => \Ochorocho\FrankenPhp\Middleware\PreserveNativeSessionCookies::class,
        ],
    ],
];

// Development only: the request profiler's Server-Timing header. Outermost
// middleware of the `core` stack, so one entry covers frontend and backend.
// The stack is cached per context together with the DI container.
if (\TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment()) {
    $middlewares['core'] = [
        \Ochorocho\FrankenPhp\Profiler\RequestProfile::PROFILE_MIDDLEWARE => [
            'target' => \Ochorocho\FrankenPhp\Profiler\ServerTimingMiddleware::class,
            'before' => ['typo3/cms-core/verify-host-header'],
        ],
    ];
}

return $middlewares;
