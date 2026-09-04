<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\DependencyInjection;

/**
 * Decides whether a class can hold per-request state, by reflection.
 *
 * Stateless means: a `readonly class`, or a class whose non-static instance
 * properties are all readonly. Static properties never block "stateless"
 * (discarding an instance does not reset them) but are reported so the
 * audit can surface process-wide state.
 */
final class ClassStateAnalyzer
{
    public const string REASON_OPAQUE = 'opaque';
    public const string REASON_UNLOADABLE = 'unloadable';
    public const string REASON_READONLY = 'readonly';
    public const string REASON_READONLY_PROPS = 'readonly-props';
    public const string REASON_MUTABLE = 'mutable';

    /**
     * @return array{stateless: bool, reason: string, mutable: list<string>, static: list<string>}
     */
    public function analyze(?string $class): array
    {
        if ($class === null || $class === '' || $class === 'object') {
            return ['stateless' => false, 'reason' => self::REASON_OPAQUE, 'mutable' => [], 'static' => []];
        }
        try {
            $reflection = new \ReflectionClass($class);
        } catch (\Throwable) {
            return ['stateless' => false, 'reason' => self::REASON_UNLOADABLE, 'mutable' => [], 'static' => []];
        }
        if ($reflection->isInterface() || $reflection->isAbstract()) {
            return ['stateless' => false, 'reason' => self::REASON_OPAQUE, 'mutable' => [], 'static' => []];
        }

        $mutable = [];
        $static = [];
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }
                if ($property->isStatic()) {
                    if (!$property->isReadOnly()) {
                        $static[] = $property->getName();
                    }
                } elseif (!$property->isReadOnly()) {
                    $mutable[] = $property->getName();
                }
            }
        }

        if ($reflection->isReadOnly()) {
            $reason = self::REASON_READONLY;
        } elseif ($mutable === []) {
            $reason = self::REASON_READONLY_PROPS;
        } else {
            $reason = self::REASON_MUTABLE;
        }
        return ['stateless' => $mutable === [], 'reason' => $reason, 'mutable' => $mutable, 'static' => $static];
    }

    /**
     * Human-readable property summary for audit output.
     */
    public function describeProperties(?string $class): string
    {
        $analysis = $this->analyze($class);
        return implode('; ', array_filter([
            $analysis['mutable'] !== [] ? 'mutable: ' . implode(',', $analysis['mutable']) : '',
            $analysis['static'] !== [] ? 'static: ' . implode(',', $analysis['static']) : '',
        ]));
    }
}
