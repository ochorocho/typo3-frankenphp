<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler\Database;

use Doctrine\DBAL\Driver;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use TYPO3\CMS\Core\Database\Middleware\UsableForConnectionInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Doctrine DBAL driver middleware that times every query for the profiler.
 * Registered through $GLOBALS['TYPO3_CONF_VARS']['DB']['globalDriverMiddlewares']
 * in Development; ConnectionPool instantiates it with makeInstance(), so it
 * must be a public service. Connections outlive requests, hence the wrappers
 * hold only the pinned collector.
 *
 * The constructor argument is optional on purpose: makeInstance() only consults
 * the container when one is set and knows the class. During `typo3 setup` and
 * any other failsafe bootstrap the install container is active, our
 * Development-only service definitions do not exist, and makeInstance() falls
 * back to `new self()`. The fallback resolution keeps that path working; with
 * no container at all the middleware holds an inert, disabled collector and
 * records nothing.
 */
final readonly class ProfilingDriverMiddleware implements UsableForConnectionInterface
{
    private RequestProfile $profile;

    public function __construct(?RequestProfile $profile = null)
    {
        $this->profile = $profile ?? self::resolveProfile();
    }

    public function canBeUsedForConnection(string $identifier, array $connectionParams): bool
    {
        return true;
    }

    public function wrap(Driver $driver): Driver
    {
        return new ProfilingDriver($driver, $this->profile);
    }

    private static function resolveProfile(): RequestProfile
    {
        try {
            $container = GeneralUtility::getContainer();
        } catch (\LogicException) {
            return new RequestProfile();
        }

        return $container->has(RequestProfile::class)
            ? $container->get(RequestProfile::class)
            : new RequestProfile();
    }
}
