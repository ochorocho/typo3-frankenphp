<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Tests\Unit\Worker\Fixtures\RecordingApplication;
use Ochorocho\FrankenPhp\Tests\Unit\Worker\Fixtures\WorkerTestContainer;
use Ochorocho\FrankenPhp\Worker\WorkerRequestCycle;
use Ochorocho\FrankenPhp\Worker\WorkerStateResetter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

final class WorkerRequestCycleTest extends TestCase
{
    private const string APPLICATION = 'test.application';

    private int $finished = 0;

    protected function setUp(): void
    {
        $this->finished = 0;
    }

    #[Test]
    public function firstRequestResetsInlineAndLaterOnesAfterTheResponse(): void
    {
        $container = $this->container();
        $cycle = $this->cycle($container);

        $first = $cycle->begin();
        self::assertInstanceOf(RecordingApplication::class, $first);
        self::assertSame(WorkerRequestCycle::MODE_INLINE, $cycle->mode());
        $first->run();
        // A plain Container has no factories: put back what the request would have instantiated.
        $container->set(WorkerTestContainer::DISCARDED, new \stdClass());
        $cycle->finish();
        self::assertSame(1, $this->finished);

        $second = $cycle->begin();
        self::assertSame($first, $second);
        self::assertSame(WorkerRequestCycle::MODE_POST, $cycle->mode());
        self::assertSame(1, $cycle->discarded(), 'the post phase reports what the previous request left behind');
        self::assertGreaterThanOrEqual(0, $cycle->postResetMicroseconds());
    }

    #[Test]
    public function withoutFinishSupportEveryRequestResetsInline(): void
    {
        $cycle = $this->cycle($this->container(), finishSupported: false);

        $cycle->begin();
        $cycle->finish();
        $cycle->begin();

        self::assertSame(0, $this->finished);
        self::assertSame(WorkerRequestCycle::MODE_INLINE, $cycle->mode());
    }

    #[Test]
    public function aFailingPostPhaseFallsBackToTheInlineResetAndKeepsTheWorkerAlive(): void
    {
        $container = $this->container();
        $boom = new \RuntimeException('post phase exploded');
        $cycle = $this->cycle($container, beforeStructuralReset: static function () use ($boom): void {
            throw $boom;
        });

        $cycle->begin();
        $cycle->finish();

        self::assertSame($boom, $cycle->lastFailure());
        $cycle->begin();
        self::assertSame(WorkerRequestCycle::MODE_INLINE, $cycle->mode());
        self::assertFalse($container->initialized(WorkerTestContainer::DISCARDED), 'the inline reset completed');
    }

    #[Test]
    public function outputWrittenAfterTheResponseIsCapturedNotSent(): void
    {
        $cycle = $this->cycle($this->container(), beforeStructuralReset: static function (): void {
            echo 'stray deprecation notice';
        });

        $cycle->begin();
        $cycle->finish();

        self::assertSame('stray deprecation notice', $cycle->strayOutput());
        $this->expectOutputString('');
    }

    #[Test]
    public function beginClearsThePreparedStateSoAnInterruptedRequestCannotReuseIt(): void
    {
        $cycle = $this->cycle($this->container());
        $cycle->begin();
        $cycle->finish();

        $cycle->begin();
        // Simulate a request that died before finish(): the next begin() must not trust the old preparation.
        $cycle->begin();

        self::assertSame(WorkerRequestCycle::MODE_INLINE, $cycle->mode());
    }

    private function container(): Container
    {
        return WorkerTestContainer::create([self::APPLICATION => new RecordingApplication()], [self::APPLICATION]);
    }

    private function cycle(Container $container, bool $finishSupported = true, ?\Closure $beforeStructuralReset = null): WorkerRequestCycle
    {
        $resetter = new WorkerStateResetter();
        return new WorkerRequestCycle(
            $resetter,
            $resetter->capture($container),
            $container,
            self::APPLICATION,
            $finishSupported ? function (): void {
                $this->finished++;
            } : null,
            $beforeStructuralReset,
        );
    }
}
