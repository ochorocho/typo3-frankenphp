<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

/**
 * Per-request timing collector of the development profiler.
 *
 * Frames nest per kind (middleware, TimeTracker, service) on an explicit
 * stack; when a frame leaves, its inclusive time is charged to the parent so
 * `own = inclusive - children`. Database queries are counted in total and
 * attributed to the innermost open middleware and TimeTracker frame. The
 * collector is a pinned service: it survives the worker's per-request reset
 * and is emptied by beginRequest(). It holds scalars and arrays only.
 */
final class RequestProfile
{
    public const string ENV_FLAG = 'FRANKENPHP_PROFILE';
    public const string ENV_DEPTH = 'FRANKENPHP_PROFILE_DEPTH';
    public const string LOG_BASENAME = 'frankenphp-profile.jsonl';
    public const string KIND_MIDDLEWARE = 'middleware';
    public const string KIND_TIMETRACKER = 'timetracker';
    public const string KIND_SERVICE = 'service';
    public const string PROFILE_MIDDLEWARE = 'ochorocho/frankenphp/profile';
    public const int TIMETRACKER_MAX_DEPTH = 3;

    private bool $enabled = false;
    private int $startedAt = 0;
    /** @var array<string, list<array{string, int, int, int, int}>> per kind: [name, startNs, childrenNs, dbCalls, dbNs] */
    private array $stacks = [];
    /** @var array<string, array{kind: string, name: string, calls: int, inclusive_ns: int, own_ns: int, db_calls: int, db_ns: int}> */
    private array $frames = [];
    /** @var array<string, int> mark name => ns since start */
    private array $marks = [];
    private int $dbCalls = 0;
    private int $dbNs = 0;
    private ?int $status = null;
    /** @var array{method: string, uri: string, host: string} */
    private array $request = ['method' => '', 'uri' => '', 'host' => ''];

    public function enable(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Empties the collector for the request that just arrived. Request
     * metadata is copied now, so writing the record after the response
     * never touches $_SERVER.
     */
    public function beginRequest(int $startNs): void
    {
        $this->startedAt = $startNs;
        $this->stacks = [];
        $this->frames = [];
        $this->marks = [];
        $this->dbCalls = 0;
        $this->dbNs = 0;
        $this->status = null;
        $this->request = [
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        ];
    }

    public function enter(string $kind, string $name): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->stacks[$kind][] = [$name, hrtime(true), 0, 0, 0];
    }

    public function leave(string $kind): void
    {
        if (!$this->enabled || empty($this->stacks[$kind])) {
            return;
        }
        [$name, $startedAt, $children, $dbCalls, $dbNs] = array_pop($this->stacks[$kind]);
        $inclusive = hrtime(true) - $startedAt;
        $depth = count($this->stacks[$kind]);
        if ($depth > 0) {
            $this->stacks[$kind][$depth - 1][2] += $inclusive;
        }
        $this->record($kind, $name, $inclusive, $inclusive - $children, $dbCalls, $dbNs);
    }

    public function mark(string $name): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->marks[$name] = hrtime(true) - $this->startedAt;
    }

    /**
     * A frame that started at a previously recorded mark and ends now; for
     * code that can only be observed through events.
     */
    public function spanFromMark(string $kind, string $name, string $mark): void
    {
        if (!$this->enabled || !isset($this->marks[$mark])) {
            return;
        }
        $duration = (hrtime(true) - $this->startedAt) - $this->marks[$mark];
        $this->record($kind, $name, $duration, $duration, 0, 0);
    }

    public function query(int $nanoseconds): void
    {
        if (!$this->enabled) {
            return;
        }
        $this->dbCalls++;
        $this->dbNs += $nanoseconds;
        foreach ([self::KIND_MIDDLEWARE, self::KIND_TIMETRACKER] as $kind) {
            $depth = count($this->stacks[$kind] ?? []);
            if ($depth > 0) {
                $this->stacks[$kind][$depth - 1][3]++;
                $this->stacks[$kind][$depth - 1][4] += $nanoseconds;
            }
        }
    }

    public function setResponseStatus(int $status): void
    {
        $this->status = $status;
    }

    /**
     * @return array<string, array{kind: string, name: string, calls: int, inclusive_ns: int, own_ns: int, db_calls: int, db_ns: int}>
     */
    public function frames(): array
    {
        return $this->frames;
    }

    /**
     * @return array<string, int>
     */
    public function marks(): array
    {
        return $this->marks;
    }

    /**
     * RFC 9110 Server-Timing: `total`, `db`, then the largest own times. Names
     * must be tokens, so `typo3/cms-frontend/site` becomes `frontend.site` and
     * the identifier moves into `desc`. The profile middleware itself is left out.
     */
    public function serverTimingHeader(int $limit = 10, int $maxBytes = 1500): string
    {
        $entries = [
            sprintf('total;dur=%.2f', (hrtime(true) - $this->startedAt) / 1e6),
            sprintf('db;dur=%.2f;desc="%d queries"', $this->dbNs / 1e6, $this->dbCalls),
        ];
        $frames = array_filter(
            $this->frames,
            static fn(array $frame): bool => $frame['name'] !== self::PROFILE_MIDDLEWARE
        );
        usort($frames, static fn(array $a, array $b): int => $b['own_ns'] <=> $a['own_ns']);
        $header = implode(', ', $entries);
        foreach (array_slice($frames, 0, $limit) as $frame) {
            $entry = sprintf(
                '%s;dur=%.2f;desc="%s"',
                self::token($frame['kind'], $frame['name']),
                $frame['own_ns'] / 1e6,
                str_replace('"', "'", $frame['name'])
            );
            if (strlen($header) + strlen($entry) + 2 > $maxBytes) {
                break;
            }
            $header .= ', ' . $entry;
        }
        return $header;
    }

    /**
     * One JSON-serialisable line for the profile log.
     *
     * @param array<string, int|string> $worker reset_us, post_reset_us, mode, discarded
     * @return array<string, mixed>
     */
    public function toRecord(int $totalNs, array $worker): array
    {
        return [
            'ts' => date('c'),
            'method' => $this->request['method'],
            'uri' => $this->request['uri'],
            'host' => $this->request['host'],
            'status' => $this->status ?? http_response_code(),
            'total_ns' => $totalNs,
            'worker' => $worker,
            'db' => ['calls' => $this->dbCalls, 'ns' => $this->dbNs],
            'frames' => array_values($this->frames),
            'marks' => $this->marks,
        ];
    }

    public static function token(string $kind, string $name): string
    {
        $short = preg_replace('#^typo3/cms-#', '', $name) ?? $name;
        $token = preg_replace('/[^A-Za-z0-9._-]+/', '.', $short) ?? $short;
        $prefix = match ($kind) {
            self::KIND_MIDDLEWARE => '',
            self::KIND_TIMETRACKER => 'tt.',
            default => 'svc.',
        };
        return $prefix . trim($token, '.');
    }

    private function record(string $kind, string $name, int $inclusive, int $own, int $dbCalls, int $dbNs): void
    {
        $key = $kind . "\0" . $name;
        $frame = $this->frames[$key] ?? ['kind' => $kind, 'name' => $name, 'calls' => 0, 'inclusive_ns' => 0, 'own_ns' => 0, 'db_calls' => 0, 'db_ns' => 0];
        $frame['calls']++;
        $frame['inclusive_ns'] += $inclusive;
        $frame['own_ns'] += $own;
        $frame['db_calls'] += $dbCalls;
        $frame['db_ns'] += $dbNs;
        $this->frames[$key] = $frame;
    }
}
