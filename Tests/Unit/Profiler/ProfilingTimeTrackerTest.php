<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Profiler;

use Ochorocho\FrankenPhp\Profiler\ProfileEventProbes;
use Ochorocho\FrankenPhp\Profiler\ProfilingTimeTracker;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProfilingTimeTrackerTest extends TestCase
{
    private RequestProfile $profile;

    protected function setUp(): void
    {
        $this->profile = new RequestProfile();
        $this->profile->enable(true);
        $this->profile->beginRequest(hrtime(true));
    }

    #[Test]
    public function recordsSpansEvenWhenCoreTrackingIsDisabled(): void
    {
        $tracker = new ProfilingTimeTracker($this->profile, isEnabled: false, maxDepth: 3);

        $tracker->push('');
        $tracker->push('Create PageInformation');
        $tracker->pull();
        $tracker->push('Page generation', 'PAGE');
        $tracker->pull();
        $tracker->pull();

        $names = array_column(array_values($this->profile->frames()), 'name');
        self::assertSame(['Create PageInformation', 'Page generation [PAGE]'], $names, 'the empty root label is skipped');
        self::assertSame([], $tracker->getTypoScriptLogStack(), 'Core state stays empty when Core tracking is off');
        self::assertArrayHasKey(ProfileEventProbes::MARK_PAGE_INFORMATION, $this->profile->marks(), 'the probes measure from the end of PageInformation creation');
    }

    #[Test]
    public function capsTheDepth(): void
    {
        $tracker = new ProfilingTimeTracker($this->profile, isEnabled: false, maxDepth: 2);

        $tracker->push('');
        $tracker->push('level1');
        $tracker->push('level2');
        $tracker->push('level3');
        $tracker->pull();
        $tracker->pull();
        $tracker->pull();
        $tracker->pull();

        self::assertSame(['level2', 'level1'], array_column(array_values($this->profile->frames()), 'name'));
    }

    #[Test]
    public function keepsCoreBehaviourWhenEnabled(): void
    {
        $tracker = new ProfilingTimeTracker($this->profile, isEnabled: true, maxDepth: 3);

        $tracker->push('');
        $tracker->push('Something');
        $tracker->pull();
        $tracker->pull();

        self::assertNotSame([], $tracker->getTypoScriptLogStack());
        self::assertCount(1, $this->profile->frames());
    }
}
