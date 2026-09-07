<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;

final class ProfilingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $wrappedDriver,
        private readonly RequestProfile $profile,
    ) {
        parent::__construct($wrappedDriver);
    }

    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        return new ProfilingConnection(parent::connect($params), $this->profile);
    }
}
