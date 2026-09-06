<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

use Random\Randomizer;

/**
 * When a worker stops taking requests and hands itself back to FrankenPHP
 * for a fresh boot: after MAX_REQUESTS requests (with a per-worker jitter,
 * so a pool started together does not recycle in lockstep) or once its
 * memory exceeds MAX_MEMORY_MB. Both bound leaks in code that was never
 * written for a long-running process; neither is a steady-state cost.
 */
final class WorkerLifetime
{
    public const string ENV_MAX_REQUESTS = 'MAX_REQUESTS';
    public const string ENV_MAX_MEMORY_MB = 'MAX_MEMORY_MB';
    public const int JITTER_PERCENT = 10;

    /**
     * @param int $maxRequests 0 = unbounded
     * @param int $maxMemoryBytes 0 = unbounded; checked after each request
     */
    public function __construct(
        public readonly int $maxRequests,
        public readonly int $maxMemoryBytes,
        public readonly ?string $warning = null,
    ) {}

    /**
     * @param array<string, mixed> $server the worker's $_SERVER (environment)
     * @param int $bootMemoryBytes memory_get_usage(true) after boot: a memory limit below it would recycle after every request
     */
    public static function fromEnvironment(array $server, int $bootMemoryBytes, ?Randomizer $randomizer = null): self
    {
        $maxRequests = max(0, (int)($server[self::ENV_MAX_REQUESTS] ?? 0));
        if ($maxRequests > 0) {
            $jitter = intdiv($maxRequests * self::JITTER_PERCENT, 100);
            $maxRequests += ($randomizer ?? new Randomizer())->getInt(-$jitter, $jitter);
        }

        $maxMemoryBytes = max(0, (int)($server[self::ENV_MAX_MEMORY_MB] ?? 0)) * 1024 * 1024;
        $warning = null;
        if ($maxMemoryBytes > 0 && $maxMemoryBytes <= $bootMemoryBytes) {
            $warning = sprintf(
                '%s=%d MiB is below the worker\'s boot footprint of %d MiB; the memory limit is ignored.',
                self::ENV_MAX_MEMORY_MB,
                intdiv($maxMemoryBytes, 1024 * 1024),
                intdiv($bootMemoryBytes, 1024 * 1024)
            );
            $maxMemoryBytes = 0;
        }
        return new self($maxRequests, $maxMemoryBytes, $warning);
    }

    public function allowsAnotherRequest(int $handledRequests, int $memoryUsageBytes): bool
    {
        if ($this->maxRequests > 0 && $handledRequests >= $this->maxRequests) {
            return false;
        }
        return $this->maxMemoryBytes === 0 || $memoryUsageBytes < $this->maxMemoryBytes;
    }
}
