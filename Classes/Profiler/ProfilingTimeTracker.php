<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

use TYPO3\CMS\Core\TimeTracker\TimeTracker;

/**
 * Replaces the TimeTracker service class in Development: Core already
 * brackets the render path with push()/pull() ("Create PageInformation",
 * "Page generation", ...), so recording those pairs yields the breakdown
 * below the middleware level. Core's own isEnabled logic is untouched.
 *
 * The root push('') is skipped and depth is capped: with LR enabled every
 * content object pushes, which would drown the log on content-heavy pages.
 */
final class ProfilingTimeTracker extends TimeTracker
{
    public const string PAGE_INFORMATION_LABEL = 'Create PageInformation';

    /** @var list<string|null> the profile frame name of each open push(), null when not recorded */
    private array $recorded = [];
    private int $maxDepth;

    public function __construct(
        private readonly RequestProfile $profile,
        bool $isEnabled = true,
        ?int $maxDepth = null,
    ) {
        parent::__construct($isEnabled);
        $this->maxDepth = $maxDepth ?? max(1, (int)(getenv(RequestProfile::ENV_DEPTH) ?: RequestProfile::TIMETRACKER_MAX_DEPTH));
    }

    public function push(string $tslabel, string $value = ''): void
    {
        $record = $this->profile->isEnabled() && $tslabel !== '' && count($this->recorded) < $this->maxDepth + 1;
        $name = $record ? ($value === '' ? $tslabel : $tslabel . ' [' . $value . ']') : null;
        $this->recorded[] = $name;
        if ($name !== null) {
            $this->profile->enter(RequestProfile::KIND_TIMETRACKER, $name);
        }
        parent::push($tslabel, $value);
    }

    public function pull(string $content = ''): void
    {
        parent::pull($content);
        $name = array_pop($this->recorded);
        if ($name === null) {
            return;
        }
        $this->profile->leave(RequestProfile::KIND_TIMETRACKER);
        if ($name === self::PAGE_INFORMATION_LABEL) {
            // The event probes measure what follows PageInformation creation from here.
            $this->profile->mark(ProfileEventProbes::MARK_PAGE_INFORMATION);
        }
    }
}
