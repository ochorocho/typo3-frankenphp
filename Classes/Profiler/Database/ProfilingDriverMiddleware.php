<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler\Database;

use Doctrine\DBAL\Driver;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use TYPO3\CMS\Core\Database\Middleware\UsableForConnectionInterface;

/**
 * Doctrine DBAL driver middleware that times every query for the profiler.
 * Registered through $GLOBALS['TYPO3_CONF_VARS']['DB']['globalDriverMiddlewares']
 * in Development; ConnectionPool instantiates it with makeInstance(), so it
 * must be a public service. Connections outlive requests, hence the wrappers
 * hold only the pinned collector.
 */
final readonly class ProfilingDriverMiddleware implements UsableForConnectionInterface
{
    public function __construct(
        private RequestProfile $profile,
    ) {}

    public function canBeUsedForConnection(string $identifier, array $connectionParams): bool
    {
        return true;
    }

    public function wrap(Driver $driver): Driver
    {
        return new ProfilingDriver($driver, $this->profile);
    }
}
