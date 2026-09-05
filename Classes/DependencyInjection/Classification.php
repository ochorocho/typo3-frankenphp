<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\DependencyInjection;

/**
 * Result of one WorkerKeepListPass run. Owns the report and every reason
 * string the audit displays.
 */
final class Classification
{
    public const string KEEP = 'keep';
    public const string DISCARD = 'discard';

    // Reason prefixes named after their source.
    public const string CURATED = 'curated';
    public const string TAG = 'tag';
    public const string SOFT = 'soft';
    public const string CONFIG = 'config';
    public const string PATTERN = 'pattern';
    public const string PINNED = 'pinned';

    /** Only these discards block a keep or a pin; intrinsic ones do not. */
    private const array EXPLICIT_DISCARD_PREFIXES = [self::CURATED, self::TAG, self::PATTERN, self::CONFIG];

    /**
     * Dumped into the compiled container, so kept small: `class` only when it differs from the id.
     *
     * @var array<string, array{category: string, reason: string, class?: string}>
     */
    private array $report = [];

    /** @var array<string, list<string>> class => ids registered under another id */
    private array $classIndex = [];

    /** @var array<string, true> */
    private array $pinned = [];

    /** Closure of the curated pins: boot-populated, must never be discarded. @var array<string, true> */
    private array $protected = [];

    /** @var array<string, string> protected id => discard reason that was ignored */
    private array $ignoredDiscards = [];

    /** @var list<array{list: string, name: string, origin: string}> */
    private array $unmatched = [];

    public static function reason(string ...$parts): string
    {
        return implode(':', $parts);
    }

    public function add(string $id, ?string $class, bool $stateless, string $reason): void
    {
        $this->report[$id] = ['category' => $stateless ? self::KEEP : self::DISCARD, 'reason' => $reason];
        if ($class !== null && $class !== $id) {
            $this->report[$id]['class'] = $class;
            $this->classIndex[$class][] = $id;
        }
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->report);
    }

    public function has(string $id): bool
    {
        return isset($this->report[$id]);
    }

    public function classOf(string $id): string
    {
        return $this->report[$id]['class'] ?? $id;
    }

    public function isKept(string $id): bool
    {
        return ($this->report[$id]['category'] ?? null) === self::KEEP;
    }

    public function isDiscarded(string $id): bool
    {
        return ($this->report[$id]['category'] ?? null) === self::DISCARD;
    }

    public function isExplicitlyDiscarded(string $id): bool
    {
        return $this->isDiscarded($id)
            && in_array(explode(':', $this->report[$id]['reason'], 2)[0], self::EXPLICIT_DISCARD_PREFIXES, true);
    }

    public function isPinned(string $id): bool
    {
        return isset($this->pinned[$id]);
    }

    /**
     * Ids a name refers to: the id itself plus services registered under another id with that class.
     *
     * @return list<string>
     */
    public function resolveNames(ServiceGraph $graph, string $name): array
    {
        $ids = [];
        $id = $graph->resolveId($name);
        if ($this->has($id)) {
            $ids[$id] = true;
        }
        foreach ($this->classIndex[ltrim($name, '\\')] ?? [] as $classId) {
            $ids[$classId] = true;
        }
        return array_keys($ids);
    }

    /**
     * First call for a known id returns true; the caller then walks its dependencies.
     */
    public function protect(string $id): bool
    {
        if (!$this->has($id) || isset($this->protected[$id])) {
            return false;
        }
        $this->protected[$id] = true;
        return true;
    }

    public function keep(string $id, string $reason): void
    {
        if (!$this->has($id) || $this->isExplicitlyDiscarded($id)) {
            return;
        }
        $this->set($id, self::KEEP, $reason);
    }

    public function discard(string $id, string $reason): void
    {
        if (!$this->has($id)) {
            return;
        }
        if ($reason !== self::CURATED && isset($this->protected[$id])) {
            $this->ignoredDiscards[$id] = $reason;
            return;
        }
        $this->set($id, self::DISCARD, $reason);
    }

    /**
     * @param list<string> $closure root included
     */
    public function pin(string $root, array $closure, string $rootReason, ServiceGraph $graph): void
    {
        foreach ($closure as $id) {
            if (isset($this->pinned[$id])) {
                continue;
            }
            $this->pinned[$id] = true;
            $reason = $id === $root ? $rootReason : self::reason('pinned-via', $root);
            if (isset($this->ignoredDiscards[$id])) {
                $reason = self::reason($reason, 'ignored-discard', $this->ignoredDiscards[$id]);
            }
            if ($graph->holdsLazyCollection($graph->definition($id))) {
                // Invisible to the closure check; the audit reader must look twice.
                $reason .= ' (holds lazily collected instances)';
            }
            $this->set($id, self::KEEP, $reason);
        }
    }

    /**
     * @param list<string> $chain root first, blocking dependency last
     */
    public function pinConflict(string $root, array $chain, string $conflict): void
    {
        $this->set($root, self::DISCARD, sprintf('pin-conflict:%s (%s)', implode(' -> ', $chain), $conflict));
    }

    public function demote(string $id, string $dependency, string $blocker): void
    {
        $this->set($id, self::DISCARD, sprintf('demoted-via:%s (%s)', $dependency, $blocker));
    }

    public function noteUnmatched(string $list, string $name, string $origin): void
    {
        $this->unmatched[] = ['list' => $list, 'name' => $name, 'origin' => $origin];
    }

    /**
     * @return list<string>
     */
    public function keepIds(): array
    {
        return array_values(array_filter($this->ids(), $this->isKept(...)));
    }

    /**
     * @return array<string, array{category: string, reason: string, class?: string}>
     */
    public function report(): array
    {
        $report = $this->report;
        ksort($report);
        return $report;
    }

    /**
     * @return list<array{list: string, name: string, origin: string}>
     */
    public function unmatched(): array
    {
        return $this->unmatched;
    }

    private function set(string $id, string $category, string $reason): void
    {
        $this->report[$id]['category'] = $category;
        $this->report[$id]['reason'] = $reason;
    }
}
