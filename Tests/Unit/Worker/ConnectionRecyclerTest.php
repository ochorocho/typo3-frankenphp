<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Worker\ConnectionRecycler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ConnectionRecyclerTest extends TestCase
{
    protected function tearDown(): void
    {
        self::setPool([]);
    }

    #[Test]
    public function keepsAnIdleConnectionWithoutTransaction(): void
    {
        $connection = $this->connection(connected: true, inTransaction: false, closeExpected: false);
        self::setPool(['Default' => $connection]);

        self::assertSame(0, (new ConnectionRecycler(60))->recycle(5));
        self::assertSame(['Default' => $connection], self::pool());
    }

    #[Test]
    public function closesAConnectionLeftInsideATransaction(): void
    {
        $connection = $this->connection(connected: true, inTransaction: true, closeExpected: true);
        self::setPool(['Default' => $connection]);

        self::assertSame(1, (new ConnectionRecycler(60))->recycle(5));
        self::assertSame([], self::pool());
    }

    #[Test]
    public function closesAConnectionAfterTheIdleLimit(): void
    {
        $connection = $this->connection(connected: true, inTransaction: false, closeExpected: true);
        self::setPool(['Default' => $connection]);

        self::assertSame(1, (new ConnectionRecycler(60))->recycle(61));
        self::assertSame([], self::pool());
    }

    #[Test]
    public function leavesLazyConnectionsAlone(): void
    {
        $connection = $this->connection(connected: false, inTransaction: false, closeExpected: false);
        self::setPool(['Default' => $connection]);

        self::assertSame(0, (new ConnectionRecycler(60))->recycle(9999));
        self::assertSame(['Default' => $connection], self::pool());
    }

    #[Test]
    public function leavesThePoolUntouchedWhenNothingIsClosed(): void
    {
        $connection = $this->connection(connected: true, inTransaction: false, closeExpected: false);
        $pool = ['Default' => $connection, 'Second' => $connection];
        self::setPool($pool);

        (new ConnectionRecycler(60))->recycle(5);

        self::assertSame($pool, self::pool());
    }

    private function connection(bool $connected, bool $inTransaction, bool $closeExpected): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn($connected);
        $connection->method('isTransactionActive')->willReturn($inTransaction);
        $connection->expects($closeExpected ? self::once() : self::never())->method('close');
        return $connection;
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
