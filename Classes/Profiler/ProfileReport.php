<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

/**
 * Medians over the recorded requests. Never the mean: one warm-up outlier
 * would otherwise dominate. Per-request sums are formed first and the median
 * taken over them, so the summary lines add up within one request's terms.
 */
final class ProfileReport
{
    /**
     * @param list<array<string, mixed>> $records
     */
    private function __construct(
        private readonly array $records,
    ) {}

    public static function fromFile(string $path, string $uriFilter = '', bool $exact = false): self
    {
        return new self(iterator_to_array(ProfileLog::read($path, $uriFilter, $exact), false));
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    public static function fromRecords(array $records): self
    {
        return new self($records);
    }

    public function requestCount(): int
    {
        return count($this->records);
    }

    public function medianTotalMs(): float
    {
        return self::median(array_map(static fn(array $r): float => ((int)$r['total_ns']) / 1e6, $this->records));
    }

    /**
     * Per span: median own / inclusive milliseconds, share of the median total,
     * median calls and database time. Sorted by own time, descending.
     *
     * @return list<array{kind: string, name: string, own_ms: float, inclusive_ms: float, share: float, calls: float, db_ms: float, db_calls: float, requests: int}>
     */
    public function rows(string $kind = 'all', float $minMs = 0.05): array
    {
        // A request without a given span counts as zero for it: in a warm series
        // the spans of the one cold render must not dominate the medians.
        $byKey = [];
        foreach ($this->records as $index => $record) {
            foreach ($record['frames'] as $frame) {
                if ($kind !== 'all' && $frame['kind'] !== $kind) {
                    continue;
                }
                $key = $frame['kind'] . "\0" . $frame['name'];
                $byKey[$key]['kind'] = $frame['kind'];
                $byKey[$key]['name'] = $frame['name'];
                $byKey[$key]['own'][$index] = $frame['own_ns'] / 1e6;
                $byKey[$key]['inclusive'][$index] = $frame['inclusive_ns'] / 1e6;
                $byKey[$key]['calls'][$index] = $frame['calls'];
                $byKey[$key]['db_ms'][$index] = ($frame['db_ns'] ?? 0) / 1e6;
                $byKey[$key]['db_calls'][$index] = $frame['db_calls'] ?? 0;
            }
        }
        $total = $this->medianTotalMs();
        $requests = count($this->records);
        $rows = [];
        foreach ($byKey as $entry) {
            $padded = static fn(array $values): array => array_values($values + array_fill(0, $requests, 0));
            $own = self::median($padded($entry['own']));
            if ($own < $minMs) {
                continue;
            }
            $rows[] = [
                'kind' => $entry['kind'],
                'name' => $entry['name'],
                'own_ms' => $own,
                'inclusive_ms' => self::median($padded($entry['inclusive'])),
                'share' => $total > 0 ? $own * 100 / $total : 0.0,
                'calls' => self::median($padded($entry['calls'])),
                'db_ms' => self::median($padded($entry['db_ms'])),
                'db_calls' => self::median($padded($entry['db_calls'])),
                'requests' => count($entry['own']),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $b['own_ms'] <=> $a['own_ms']);
        return $rows;
    }

    /**
     * @return array{total_ms: float, reset_ms: float, post_reset_ms: float, middleware_own_ms: float, timetracker_own_ms: float, db_ms: float, db_calls: float, rest_ms: float}
     */
    public function summary(): array
    {
        $per = ['reset' => [], 'post' => [], 'middleware' => [], 'timetracker' => [], 'db' => [], 'db_calls' => [], 'rest' => []];
        foreach ($this->records as $record) {
            $middleware = 0;
            $timetracker = 0;
            foreach ($record['frames'] as $frame) {
                if ($frame['kind'] === RequestProfile::KIND_MIDDLEWARE) {
                    $middleware += $frame['own_ns'];
                } elseif ($frame['kind'] === RequestProfile::KIND_TIMETRACKER) {
                    $timetracker += $frame['own_ns'];
                }
            }
            $reset = ((int)($record['worker']['reset_us'] ?? 0)) * 1000;
            $per['reset'][] = $reset / 1e6;
            $per['post'][] = ((int)($record['worker']['post_reset_us'] ?? 0)) / 1e3;
            $per['middleware'][] = $middleware / 1e6;
            $per['timetracker'][] = $timetracker / 1e6;
            $per['db'][] = ((int)($record['db']['ns'] ?? 0)) / 1e6;
            $per['db_calls'][] = (int)($record['db']['calls'] ?? 0);
            $per['rest'][] = ((int)$record['total_ns'] - $middleware - $reset) / 1e6;
        }
        return [
            'total_ms' => $this->medianTotalMs(),
            'reset_ms' => self::median($per['reset']),
            'post_reset_ms' => self::median($per['post']),
            'middleware_own_ms' => self::median($per['middleware']),
            'timetracker_own_ms' => self::median($per['timetracker']),
            'db_ms' => self::median($per['db']),
            'db_calls' => self::median($per['db_calls']),
            'rest_ms' => self::median($per['rest']),
        ];
    }

    /**
     * @param list<int|float> $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        return $n % 2 ? (float)$values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
