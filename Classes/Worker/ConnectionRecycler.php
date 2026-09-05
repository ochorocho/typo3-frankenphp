<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Keeps database connections across worker requests.
 *
 * Reconnecting per request reopens the socket (MySQL: TCP + auth) or the
 * file (SQLite: open + schema parse) and was the dominant cost under load
 * in the sandbox. TYPO3 already supports the same lifetime through
 * persistentConnection, so cross-request DB sessions are an accepted mode.
 * A connection is closed when the previous request died inside a
 * transaction, or after a long idle period so the server's wait_timeout
 * never closes it first.
 */
final readonly class ConnectionRecycler
{
    public function __construct(
        private int $maxIdleSeconds = KeepList::CONNECTION_MAX_IDLE_SECONDS,
    ) {}

    /**
     * @param int $idleSeconds seconds since the previous request started
     * @return int number of connections closed
     */
    public function recycle(int $idleSeconds): int
    {
        $kept = [];
        $closed = 0;
        foreach (self::pool() as $name => $connection) {
            if ($this->shouldClose($connection, $idleSeconds)) {
                $connection->close();
                $closed++;
                continue;
            }
            $kept[$name] = $connection;
        }
        self::setPool($kept);
        return $closed;
    }

    public function shouldClose(Connection $connection, int $idleSeconds): bool
    {
        if (!$connection->isConnected()) {
            return false;
        }
        return $connection->isTransactionActive() || $idleSeconds > $this->maxIdleSeconds;
    }

    /**
     * @return array<string, Connection>
     */
    private static function pool(): array
    {
        return \Closure::bind(static fn(): array => ConnectionPool::$connections, null, ConnectionPool::class)();
    }

    /**
     * @param array<string, Connection> $connections
     */
    private static function setPool(array $connections): void
    {
        \Closure::bind(static function () use ($connections): void {
            ConnectionPool::$connections = $connections;
        }, null, ConnectionPool::class)();
    }
}
