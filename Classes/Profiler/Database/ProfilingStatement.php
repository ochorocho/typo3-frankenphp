<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler\Database;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;

final class ProfilingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $wrappedStatement,
        private readonly RequestProfile $profile,
    ) {
        parent::__construct($wrappedStatement);
    }

    public function execute(): Result
    {
        if (!$this->profile->isEnabled()) {
            return parent::execute();
        }
        $start = hrtime(true);
        try {
            return parent::execute();
        } finally {
            $this->profile->query(hrtime(true) - $start);
        }
    }
}
