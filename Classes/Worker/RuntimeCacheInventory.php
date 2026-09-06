<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Development aid: records what a request left in cache.runtime before the
 * worker flushes it, so KeepList::RUNTIME_CACHE_KEEP_* can be extended from
 * evidence. One tab-separated line per entry: request, key, type, bytes.
 * `frankenphp:audit --runtime-cache` groups the log by key shape.
 */
final readonly class RuntimeCacheInventory
{
    public const string ENV_FLAG = 'FRANKENPHP_RUNTIME_CACHE_INVENTORY';
    public const string LOG_BASENAME = 'frankenphp-runtime-cache.log';

    public function __construct(
        private string $logFile,
    ) {}

    public function record(ContainerInterface $container): void
    {
        if (!$container->has('cache.runtime')) {
            return;
        }
        $runtimeCache = $container->get('cache.runtime');
        if (!$runtimeCache instanceof FrontendInterface) {
            return;
        }
        $backend = $runtimeCache->getBackend();
        if (!$backend instanceof TransientMemoryBackend) {
            return;
        }
        /** @var array<string, mixed> $entries */
        $entries = \Closure::bind(static fn(): array => $backend->entries, null, TransientMemoryBackend::class)();
        $request = ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?');
        $lines = '';
        foreach ($entries as $key => $value) {
            $lines .= $request . "\t" . $key . "\t" . self::describe($value) . "\n";
        }
        if ($lines !== '') {
            @file_put_contents($this->logFile, $lines, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Groups a log file written by record() by key shape, streaming: a busy
     * session produces hundreds of megabytes.
     *
     * @return array<string, array{count: int, requests: int, bytes: int, example: string}> keyed by normalised key, most entries first
     */
    public static function summarizeFile(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        try {
            return self::summarizeLines((static function () use ($handle): \Generator {
                while (($line = fgets($handle)) !== false) {
                    yield $line;
                }
            })());
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, array{count: int, requests: int, bytes: int, example: string}>
     */
    public static function summarize(string $log): array
    {
        return self::summarizeLines(explode("\n", $log));
    }

    /**
     * @param iterable<string> $lines
     * @return array<string, array{count: int, requests: int, bytes: int, example: string}>
     */
    private static function summarizeLines(iterable $lines): array
    {
        $groups = [];
        $requestsPerGroup = [];
        foreach ($lines as $line) {
            $columns = explode("\t", rtrim($line, "\n"));
            if (count($columns) < 4) {
                continue;
            }
            [$request, $key, , $bytes] = $columns;
            $shape = self::normalizeKey($key);
            $groups[$shape] ??= ['count' => 0, 'requests' => 0, 'bytes' => 0, 'example' => $key];
            $groups[$shape]['count']++;
            $groups[$shape]['bytes'] += max(0, (int)$bytes);
            $requestsPerGroup[$shape][$request] = true;
        }
        foreach ($requestsPerGroup as $shape => $requests) {
            $groups[$shape]['requests'] = count($requests);
        }
        uasort($groups, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
        return $groups;
    }

    /**
     * Replaces hashes and numbers so keys of the same origin fall into one group.
     */
    public static function normalizeKey(string $key): string
    {
        // "_" counts as a word character, so \b cannot delimit hashes in keys
        // like getPage_<md5>; use explicit non-alphanumeric boundaries.
        $shape = preg_replace('/(?<![0-9A-Za-z])[0-9a-f]{40}(?![0-9A-Za-z])/', '<sha1>', $key) ?? $key;
        $shape = preg_replace('/(?<![0-9A-Za-z])[0-9a-f]{32}(?![0-9A-Za-z])/', '<md5>', $shape) ?? $shape;
        $shape = preg_replace('/(?<![0-9A-Za-z])[0-9a-f]{16}(?![0-9A-Za-z])/', '<xxh3>', $shape) ?? $shape;
        return preg_replace('/(?<![0-9A-Za-z])\d+(?![0-9A-Za-z])/', '<n>', $shape) ?? $shape;
    }

    private static function describe(mixed $value): string
    {
        $type = is_object($value) ? $value::class : gettype($value);
        try {
            $bytes = strlen(serialize($value));
        } catch (\Throwable) {
            $bytes = -1;
        }
        return $type . "\t" . $bytes;
    }
}
