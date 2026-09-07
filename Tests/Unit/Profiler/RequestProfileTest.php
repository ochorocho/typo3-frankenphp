<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Profiler;

use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestProfileTest extends TestCase
{
    private function profile(): RequestProfile
    {
        $profile = new RequestProfile();
        $profile->enable(true);
        $profile->beginRequest(hrtime(true));
        return $profile;
    }

    /**
     * @return array<string, array{kind: string, name: string, calls: int, inclusive_ns: int, own_ns: int, db_calls: int, db_ns: int}>
     */
    private function byName(RequestProfile $profile): array
    {
        $frames = [];
        foreach ($profile->frames() as $frame) {
            $frames[$frame['name']] = $frame;
        }
        return $frames;
    }

    #[Test]
    public function ownTimeExcludesNestedFrames(): void
    {
        $profile = $this->profile();
        $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'outer');
        $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'inner');
        usleep(2000);
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);

        $frames = $this->byName($profile);
        self::assertSame($frames['outer']['inclusive_ns'] - $frames['inner']['inclusive_ns'], $frames['outer']['own_ns']);
        self::assertGreaterThan(1_000_000, $frames['inner']['own_ns']);
        self::assertLessThan($frames['inner']['own_ns'], $frames['outer']['own_ns']);
    }

    #[Test]
    public function kindsDoNotChargeEachOther(): void
    {
        $profile = $this->profile();
        $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'mw');
        $profile->enter(RequestProfile::KIND_TIMETRACKER, 'tt');
        usleep(1000);
        $profile->leave(RequestProfile::KIND_TIMETRACKER);
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);

        $frames = $this->byName($profile);
        self::assertSame($frames['mw']['inclusive_ns'], $frames['mw']['own_ns'], 'a TimeTracker frame is not a child of the middleware frame');
    }

    #[Test]
    public function repeatedFramesAccumulateCalls(): void
    {
        $profile = $this->profile();
        foreach ([1, 2] as $_) {
            $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'twice');
            $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        }

        self::assertSame(2, $this->byName($profile)['twice']['calls']);
    }

    #[Test]
    public function leaveWithoutEnterIsIgnoredAndDisabledProfileRecordsNothing(): void
    {
        $profile = $this->profile();
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        self::assertSame([], $profile->frames());

        $profile->enable(false);
        $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'x');
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        $profile->query(5);
        self::assertSame([], $profile->frames());
        self::assertSame(0, $profile->toRecord(1, [])['db']['calls']);
    }

    #[Test]
    public function queriesAreCountedAndChargedToTheInnermostOpenFrames(): void
    {
        $profile = $this->profile();
        $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'outer');
        $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'inner');
        $profile->enter(RequestProfile::KIND_TIMETRACKER, 'Create PageInformation');
        $profile->query(300_000);
        $profile->query(200_000);
        $profile->leave(RequestProfile::KIND_TIMETRACKER);
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);

        $frames = $this->byName($profile);
        self::assertSame(2, $frames['inner']['db_calls']);
        self::assertSame(500_000, $frames['inner']['db_ns']);
        self::assertSame(0, $frames['outer']['db_calls'], 'only the innermost frame is charged');
        self::assertSame(2, $frames['Create PageInformation']['db_calls']);
        self::assertSame(['calls' => 2, 'ns' => 500_000], $profile->toRecord(1, [])['db']);
    }

    #[Test]
    public function spanFromMarkRecordsTheTimeSinceTheMark(): void
    {
        $profile = $this->profile();
        $profile->mark('a');
        usleep(1000);
        $profile->spanFromMark(RequestProfile::KIND_SERVICE, 'after a', 'a');
        $profile->spanFromMark(RequestProfile::KIND_SERVICE, 'never', 'missing');

        $frames = $this->byName($profile);
        self::assertGreaterThan(900_000, $frames['after a']['own_ns']);
        self::assertArrayNotHasKey('never', $frames);
        self::assertArrayHasKey('a', $profile->marks());
    }

    #[Test]
    public function serverTimingUsesTokensKeepsIdentifiersAndSkipsItself(): void
    {
        $profile = $this->profile();
        foreach (['typo3/cms-frontend/site', RequestProfile::PROFILE_MIDDLEWARE, 'typo3/cms-core/normalized-params-attribute'] as $name) {
            $profile->enter(RequestProfile::KIND_MIDDLEWARE, $name);
            $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        }
        $profile->enter(RequestProfile::KIND_TIMETRACKER, 'Create PageInformation');
        $profile->leave(RequestProfile::KIND_TIMETRACKER);
        $profile->query(1_000_000);

        $header = $profile->serverTimingHeader();

        self::assertStringStartsWith('total;dur=', $header);
        self::assertStringContainsString('db;dur=1.00;desc="1 queries"', $header);
        self::assertStringContainsString('frontend.site;dur=', $header);
        self::assertStringContainsString('desc="typo3/cms-frontend/site"', $header);
        self::assertStringContainsString('tt.Create.PageInformation;dur=', $header);
        self::assertStringNotContainsString('ochorocho', $header);
        self::assertMatchesRegularExpression('/^([A-Za-z0-9._-]+;dur=[0-9.]+(;desc="[^"]*")?)(, [A-Za-z0-9._-]+;dur=[0-9.]+(;desc="[^"]*")?)*$/', $header);
    }

    #[Test]
    public function serverTimingRespectsTheByteCap(): void
    {
        $profile = $this->profile();
        for ($i = 0; $i < 40; $i++) {
            $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'vendor/package/middleware-number-' . $i);
            $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        }

        self::assertLessThanOrEqual(400, strlen($profile->serverTimingHeader(40, 400)));
    }

    #[Test]
    public function beginRequestCopiesRequestMetadataAndClearsState(): void
    {
        $profile = new RequestProfile();
        $profile->enable(true);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/camino/';
        $_SERVER['HTTP_HOST'] = 'localhost:8885';
        $profile->beginRequest(hrtime(true));
        $profile->enter(RequestProfile::KIND_MIDDLEWARE, 'x');
        $profile->leave(RequestProfile::KIND_MIDDLEWARE);
        $profile->setResponseStatus(200);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST']);

        $record = $profile->toRecord(42, ['mode' => 'post']);
        self::assertSame(['GET', '/camino/', 'localhost:8885', 200, 42, ['mode' => 'post']], [$record['method'], $record['uri'], $record['host'], $record['status'], $record['total_ns'], $record['worker']]);
        self::assertCount(1, $record['frames']);

        $profile->beginRequest(hrtime(true));
        self::assertSame([], $profile->frames());
        self::assertSame([], $profile->marks());
    }
}
