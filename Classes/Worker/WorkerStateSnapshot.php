<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

/**
 * Immutable snapshot of TYPO3 singleton state captured immediately after
 * Bootstrap::init() and replayed after every worker request, plus the
 * compile-time keep set the discard step consults.
 */
final class WorkerStateSnapshot
{
    /**
     * @param array<string, mixed> $pageRendererState
     * @param array<string, mixed> $assetCollectorState
     * @param array<string, mixed> $metaTagRegistryState
     * @param array<string, class-string> $menuTypeToClassMapping
     * @param array<string, true>|null $keepIds service ids that survive a request; null when the
     *                                          container carries no classification (nothing is discarded)
     */
    public function __construct(
        public readonly array $pageRendererState,
        public readonly array $assetCollectorState,
        public readonly array $metaTagRegistryState,
        public readonly array $menuTypeToClassMapping,
        public readonly ?array $keepIds = null,
    ) {}
}
