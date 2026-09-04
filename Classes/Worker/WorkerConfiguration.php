<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

/**
 * Lifecycle overrides declared by packages in Configuration/FrankenPhpWorker.php
 * (and by the project in config/system/frankenphp-worker.php), merged in load
 * order. Every entry remembers its origin (package key or "project") so the
 * audit can attribute the decision.
 *
 * Pure data: the DI container is compiled once and the file must not carry
 * closures. Per-request resets of pinned services belong into a listener for
 * WorkerRequestStartingEvent.
 */
final readonly class WorkerConfiguration
{
    public const string KEY_PIN = 'pin';
    public const string KEY_KEEP = 'keep';
    public const string KEY_DISCARD = 'discard';
    public const string KEY_DISCARD_PATTERNS = 'discardPatterns';

    /** @var list<string> */
    private const array KEYS = [self::KEY_PIN, self::KEY_KEEP, self::KEY_DISCARD, self::KEY_DISCARD_PATTERNS];

    /**
     * @param array<string, string> $pinned service id or class name => origin
     * @param array<string, string> $keep service id or class name => origin
     * @param array<string, string> $discard service id or class name => origin
     * @param array<string, string> $discardPatterns regular expression => origin
     */
    public function __construct(
        public array $pinned = [],
        public array $keep = [],
        public array $discard = [],
        public array $discardPatterns = [],
    ) {}

    /**
     * Validates the return value of a configuration file. Fails fast: a typo
     * in the file must break the container build, not silently keep state.
     */
    public static function fromArray(mixed $data, string $origin, string $file): self
    {
        if (!is_array($data)) {
            throw new \UnexpectedValueException(sprintf(
                '%s must return an array, %s returned.',
                $file,
                get_debug_type($data)
            ));
        }
        $unknown = array_diff(array_keys($data), self::KEYS);
        if ($unknown !== []) {
            throw new \UnexpectedValueException(sprintf(
                '%s: unknown key(s) "%s", allowed are "%s".',
                $file,
                implode('", "', array_map('strval', $unknown)),
                implode('", "', self::KEYS)
            ));
        }

        $patterns = self::strings($data, self::KEY_DISCARD_PATTERNS, $origin, $file);
        foreach (array_keys($patterns) as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \UnexpectedValueException(sprintf(
                    '%s: "%s" in "%s" is not a valid regular expression.',
                    $file,
                    $pattern,
                    self::KEY_DISCARD_PATTERNS
                ));
            }
        }

        return new self(
            pinned: self::names($data, self::KEY_PIN, $origin, $file),
            keep: self::names($data, self::KEY_KEEP, $origin, $file),
            discard: self::names($data, self::KEY_DISCARD, $origin, $file),
            discardPatterns: $patterns,
        );
    }

    /**
     * Later origins win when both name the same entry.
     */
    public function merge(self $other): self
    {
        return new self(
            pinned: [...$this->pinned, ...$other->pinned],
            keep: [...$this->keep, ...$other->keep],
            discard: [...$this->discard, ...$other->discard],
            discardPatterns: [...$this->discardPatterns, ...$other->discardPatterns],
        );
    }

    public function isEmpty(): bool
    {
        return $this->pinned === [] && $this->keep === [] && $this->discard === [] && $this->discardPatterns === [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    private static function names(array $data, string $key, string $origin, string $file): array
    {
        $names = [];
        foreach (array_keys(self::strings($data, $key, $origin, $file)) as $name) {
            $names[ltrim($name, '\\')] = $origin;
        }
        return $names;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    private static function strings(array $data, string $key, string $origin, string $file): array
    {
        $entries = $data[$key] ?? [];
        if (!is_array($entries)) {
            throw new \UnexpectedValueException(sprintf(
                '%s: "%s" must be a list of strings, %s given.',
                $file,
                $key,
                get_debug_type($entries)
            ));
        }
        $strings = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \UnexpectedValueException(sprintf(
                    '%s: "%s" must only contain non-empty strings, %s found.',
                    $file,
                    $key,
                    is_string($entry) ? 'an empty string' : get_debug_type($entry)
                ));
            }
            $strings[$entry] = $origin;
        }
        return $strings;
    }
}
