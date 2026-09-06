<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Worker\WorkerLifetime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class WorkerLifetimeTest extends TestCase
{
    private const int MIB = 1024 * 1024;

    #[Test]
    public function unboundedWithoutConfiguration(): void
    {
        $lifetime = WorkerLifetime::fromEnvironment([], 60 * self::MIB);

        self::assertSame(0, $lifetime->maxRequests);
        self::assertSame(0, $lifetime->maxMemoryBytes);
        self::assertTrue($lifetime->allowsAnotherRequest(1_000_000, 4_000 * self::MIB));
    }

    #[Test]
    public function maxRequestsGetsAPerWorkerJitterOfTenPercent(): void
    {
        $seen = [];
        for ($seed = 0; $seed < 50; $seed++) {
            $lifetime = WorkerLifetime::fromEnvironment(['MAX_REQUESTS' => '1000'], 0, new Randomizer(new Mt19937($seed)));
            self::assertGreaterThanOrEqual(900, $lifetime->maxRequests);
            self::assertLessThanOrEqual(1100, $lifetime->maxRequests);
            $seen[$lifetime->maxRequests] = true;
        }
        self::assertGreaterThan(1, count($seen), 'workers must not all pick the same limit');
    }

    #[Test]
    public function stopsAfterTheJitteredRequestLimit(): void
    {
        $lifetime = new WorkerLifetime(maxRequests: 3, maxMemoryBytes: 0);

        self::assertTrue($lifetime->allowsAnotherRequest(2, 0));
        self::assertFalse($lifetime->allowsAnotherRequest(3, 0));
    }

    #[Test]
    public function stopsOnceMemoryReachesTheLimit(): void
    {
        $lifetime = WorkerLifetime::fromEnvironment(['MAX_MEMORY_MB' => '512'], 60 * self::MIB);

        self::assertSame(512 * self::MIB, $lifetime->maxMemoryBytes);
        self::assertTrue($lifetime->allowsAnotherRequest(10, 511 * self::MIB));
        self::assertFalse($lifetime->allowsAnotherRequest(10, 512 * self::MIB));
        self::assertNull($lifetime->warning);
    }

    #[Test]
    public function aMemoryLimitBelowTheBootFootprintIsIgnoredWithAWarning(): void
    {
        $lifetime = WorkerLifetime::fromEnvironment(['MAX_MEMORY_MB' => '32'], 60 * self::MIB);

        self::assertSame(0, $lifetime->maxMemoryBytes);
        self::assertStringContainsString('MAX_MEMORY_MB=32 MiB is below', (string)$lifetime->warning);
        self::assertTrue($lifetime->allowsAnotherRequest(1, 4_000 * self::MIB));
    }
}
