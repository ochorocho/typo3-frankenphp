<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

/**
 * Lifecycle overrides from Configuration/FrankenPhpWorker.php files, each
 * entry tagged with its origin (package key or "project") for the audit.
 * Pure data: it is baked into the compiled container.
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
     * Validates a file's return value. A typo must break the container build, not keep state.
     */
    public static function fromArray(mixed $data, string $origin, string $file): self
    {
        if (!is_array($data)) {
            throw new \UnexpectedValueException(sprintf('%s must return an array, %s returned.', $file, get_debug_type($data)));
        }
        $unknown = array_diff(array_keys($data), self::KEYS);
        if ($unknown !== []) {
            self::reject($file, sprintf(
                'unknown key(s) "%s", allowed are "%s".',
                implode('", "', array_map('strval', $unknown)),
                implode('", "', self::KEYS)
            ));
        }

        $patterns = self::strings($data, self::KEY_DISCARD_PATTERNS, $origin, $file);
        foreach (array_keys($patterns) as $pattern) {
            if (@preg_match($pattern, '') === false) {
                self::reject($file, sprintf('"%s" in "%s" is not a valid regular expression.', $pattern, self::KEY_DISCARD_PATTERNS));
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
            self::reject($file, sprintf('"%s" must be a list of strings, %s given.', $key, get_debug_type($entries)));
        }
        $strings = [];
        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '') {
                $found = is_string($entry) ? 'an empty string' : get_debug_type($entry);
                self::reject($file, sprintf('"%s" must only contain non-empty strings, %s found.', $key, $found));
            }
            $strings[$entry] = $origin;
        }
        return $strings;
    }

    private static function reject(string $file, string $message): never
    {
        throw new \UnexpectedValueException($file . ': ' . $message);
    }
}
