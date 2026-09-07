<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

/**
 * The profile log: one JSON object per request, appended after the response.
 */
final readonly class ProfileLog
{
    public function __construct(
        private string $path,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function append(array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Streams the records, optionally filtered by a URI substring (or the exact URI).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function read(string $path, string $uriFilter = '', bool $exact = false): \Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                if (!is_array($record) || !isset($record['frames'])) {
                    continue;
                }
                $uri = (string)($record['uri'] ?? '');
                if ($uriFilter !== '' && ($exact ? $uri !== $uriFilter : !str_contains($uri, $uriFilter))) {
                    continue;
                }
                yield $record;
            }
        } finally {
            fclose($handle);
        }
    }
}
