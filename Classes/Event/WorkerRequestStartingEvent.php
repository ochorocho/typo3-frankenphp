<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Event;

use Psr\Container\ContainerInterface;

/**
 * Dispatched at the start of every FrankenPHP worker request, after the
 * built-in reset has completed but before the TYPO3 Application runs.
 * `$_SERVER` and `EXEC_TIME` already describe the new request.
 *
 * Listeners can perform additional per-request resets (cache front-ends,
 * custom registries, third-party singletons). Keep them cheap: unlike the
 * built-in structural reset, which runs after the previous response, this
 * event runs inside the client-visible latency.
 */
final class WorkerRequestStartingEvent
{
    public function __construct(
        public readonly ContainerInterface $container,
    ) {}
}
