<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler\Database;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;

final class ProfilingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $wrappedConnection,
        private readonly RequestProfile $profile,
    ) {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): Statement
    {
        return new ProfilingStatement(parent::prepare($sql), $this->profile);
    }

    public function query(string $sql): Result
    {
        if (!$this->profile->isEnabled()) {
            return parent::query($sql);
        }
        $start = hrtime(true);
        try {
            return parent::query($sql);
        } finally {
            $this->profile->query(hrtime(true) - $start);
        }
    }

    public function exec(string $sql): int|string
    {
        if (!$this->profile->isEnabled()) {
            return parent::exec($sql);
        }
        $start = hrtime(true);
        try {
            return parent::exec($sql);
        } finally {
            $this->profile->query(hrtime(true) - $start);
        }
    }
}
