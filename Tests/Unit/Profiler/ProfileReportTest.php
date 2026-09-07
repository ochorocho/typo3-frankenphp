<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Profiler;

use Ochorocho\FrankenPhp\Profiler\ProfileLog;
use Ochorocho\FrankenPhp\Profiler\ProfileReport;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProfileReportTest extends TestCase
{
    /**
     * @param array<string, int> $own name => own ns (middleware kind)
     * @return array<string, mixed>
     */
    private function record(string $uri, int $totalNs, array $own, int $resetUs = 20, int $dbCalls = 0, int $dbNs = 0): array
    {
        $frames = [];
        foreach ($own as $name => $ns) {
            $frames[] = ['kind' => RequestProfile::KIND_MIDDLEWARE, 'name' => $name, 'calls' => 1, 'inclusive_ns' => $ns, 'own_ns' => $ns, 'db_calls' => 0, 'db_ns' => 0];
        }
        return ['uri' => $uri, 'total_ns' => $totalNs, 'worker' => ['reset_us' => $resetUs, 'post_reset_us' => 150, 'mode' => 'post', 'discarded' => 67], 'db' => ['calls' => $dbCalls, 'ns' => $dbNs], 'frames' => $frames, 'marks' => []];
    }

    #[Test]
    public function medianIsTheMiddleValueOrTheMeanOfTheTwoMiddles(): void
    {
        self::assertSame(3.0, ProfileReport::median([5, 1, 3]));
        self::assertSame(2.5, ProfileReport::median([1, 4, 2, 3]));
        self::assertSame(0.0, ProfileReport::median([]));
    }

    #[Test]
    public function rowsAreMediansSortedByOwnTimeWithShareOfTheMedianTotal(): void
    {
        $report = ProfileReport::fromRecords([
            $this->record('/a', 10_000_000, ['slow' => 4_000_000, 'fast' => 1_000_000, 'tiny' => 10_000]),
            $this->record('/a', 12_000_000, ['slow' => 6_000_000, 'fast' => 1_000_000, 'tiny' => 10_000]),
            $this->record('/a', 11_000_000, ['slow' => 5_000_000, 'fast' => 2_000_000, 'tiny' => 10_000]),
        ]);

        $rows = $report->rows();

        self::assertSame(['slow', 'fast'], array_column($rows, 'name'), 'tiny is below the 0.05 ms cut-off');
        self::assertSame(5.0, $rows[0]['own_ms']);
        self::assertEqualsWithDelta(5.0 * 100 / 11.0, $rows[0]['share'], 0.001);
        self::assertSame(11.0, $report->medianTotalMs());
    }

    #[Test]
    public function aSpanMissingFromMostRequestsCountsAsZeroThere(): void
    {
        $report = ProfileReport::fromRecords([
            $this->record('/a', 200_000_000, ['render' => 180_000_000, 'common' => 1_000_000]),
            $this->record('/a', 5_000_000, ['common' => 1_000_000]),
            $this->record('/a', 5_000_000, ['common' => 1_000_000]),
        ]);

        $rows = $report->rows();

        self::assertSame(['common'], array_column($rows, 'name'), 'the cold render span is a zero in two of three requests');
        self::assertSame(3, $rows[0]['requests']);
    }

    #[Test]
    public function summarySumsPerRequestBeforeTakingTheMedian(): void
    {
        $report = ProfileReport::fromRecords([
            $this->record('/a', 10_000_000, ['x' => 2_000_000, 'y' => 1_000_000], resetUs: 20, dbCalls: 4, dbNs: 800_000),
            $this->record('/a', 20_000_000, ['x' => 8_000_000, 'y' => 4_000_000], resetUs: 40, dbCalls: 6, dbNs: 1_200_000),
            $this->record('/a', 30_000_000, ['x' => 9_000_000, 'y' => 6_000_000], resetUs: 60, dbCalls: 8, dbNs: 1_600_000),
        ]);

        $summary = $report->summary();

        self::assertSame(12.0, $summary['middleware_own_ms']);
        self::assertSame(20.0, $summary['total_ms']);
        self::assertSame(0.04, $summary['reset_ms']);
        self::assertSame(6.0, $summary['db_calls']);
        self::assertEqualsWithDelta(20.0 - 12.0 - 0.04, $summary['rest_ms'], 0.001);
    }

    #[Test]
    public function fromFileFiltersByUriAndSkipsBrokenLines(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'profile-');
        $log = new ProfileLog($path);
        $log->append($this->record('/camino/', 1_000_000, ['a' => 500_000]));
        $log->append($this->record('/typo3/main', 2_000_000, ['a' => 500_000]));
        file_put_contents($path, "not json\n", FILE_APPEND);

        self::assertSame(1, ProfileReport::fromFile($path, '/camino')->requestCount());
        $log->append($this->record('/camino/faqs', 1_000_000, ['a' => 500_000]));
        self::assertSame(2, ProfileReport::fromFile($path, '/camino/')->requestCount(), 'substring matches both Camino pages');
        self::assertSame(1, ProfileReport::fromFile($path, '/camino/', exact: true)->requestCount());
        self::assertSame(3, ProfileReport::fromFile($path)->requestCount());
        unlink($path);
    }
}
