<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\DependencyInjection\WorkerKeepListPass;
use Ochorocho\FrankenPhp\Tests\Unit\Worker\Fixtures\WorkerTestContainer;
use Ochorocho\FrankenPhp\Worker\WorkerStateResetter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\RequestId;

final class WorkerStateResetterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        foreach (['EXEC_TIME', 'ACCESS_TIME', 'SIM_EXEC_TIME', 'SIM_ACCESS_TIME', 'BE_USER'] as $name) {
            $this->globalsBackup[$name] = $GLOBALS[$name] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $name => $value) {
            if ($value === null) {
                unset($GLOBALS[$name]);
            } else {
                $GLOBALS[$name] = $value;
            }
        }
    }

    #[Test]
    public function afterResponseDiscardsServicesOutsideTheKeepSet(): void
    {
        $container = WorkerTestContainer::create();
        $resetter = new WorkerStateResetter();

        $discarded = $resetter->afterResponse($resetter->capture($container), $container);

        self::assertSame(1, $discarded);
        self::assertTrue($container->initialized(WorkerTestContainer::KEPT));
        self::assertFalse($container->initialized(WorkerTestContainer::DISCARDED));
    }

    #[Test]
    public function afterResponseRotatesTheRequestId(): void
    {
        $container = WorkerTestContainer::create();
        $resetter = new WorkerStateResetter();
        $before = $container->get(WorkerKeepListPass::REQUEST_ID_SERVICE);

        $resetter->afterResponse($resetter->capture($container), $container);

        $after = $container->get(WorkerKeepListPass::REQUEST_ID_SERVICE);
        self::assertInstanceOf(RequestId::class, $after);
        self::assertNotSame($before, $after);
    }

    #[Test]
    public function afterResponseFlushesTheRuntimeCacheExceptRequestIndependentEntries(): void
    {
        $container = WorkerTestContainer::create();
        $resetter = new WorkerStateResetter();
        $runtimeCache = $container->get('cache.runtime');
        self::assertInstanceOf(FrontendInterface::class, $runtimeCache);
        $runtimeCache->set('labels_core', ['x'], ['tag_a']);
        $runtimeCache->set('typo3db_1f2e3d-tableinfo-pages', ['uid' => []], ['tag_a']);
        $runtimeCache->set('rootline-localcache-1_0_0_0', [[]], ['tag_a']);

        $resetter->afterResponse($resetter->capture($container), $container);

        self::assertTrue($runtimeCache->has('labels_core'));
        self::assertTrue($runtimeCache->has('typo3db_1f2e3d-tableinfo-pages'));
        self::assertFalse($runtimeCache->has('rootline-localcache-1_0_0_0'));
        $backend = $runtimeCache->getBackend();
        self::assertInstanceOf(TaggableBackendInterface::class, $backend);
        self::assertSame(['labels_core', 'typo3db_1f2e3d-tableinfo-pages'], $backend->findIdentifiersByTag('tag_a'), 'kept entries keep their tags, removed ones lose them');
    }

    #[Test]
    public function afterResponseRemovesEveryContextAspect(): void
    {
        $container = WorkerTestContainer::create();
        $resetter = new WorkerStateResetter();
        $context = $container->get(Context::class);
        self::assertInstanceOf(Context::class, $context);
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2020-01-01')));
        $context->setAspect('custom', new DateTimeAspect(new \DateTimeImmutable('2020-01-01')));

        $resetter->afterResponse($resetter->capture($container), $container);

        // hasAspect() answers true for the built-in names whether or not an
        // instance exists, so read the storage directly.
        $aspects = \Closure::bind(static fn(): array => $context->aspects, null, Context::class)();
        self::assertSame([], $aspects);
        self::assertFalse($context->hasAspect('custom'));
    }

    #[Test]
    public function afterResponseLeavesTheClockToTheNextRequest(): void
    {
        $container = WorkerTestContainer::create();
        $resetter = new WorkerStateResetter();
        $GLOBALS['EXEC_TIME'] = 1000;
        $GLOBALS['BE_USER'] = new \stdClass();

        $resetter->afterResponse($resetter->capture($container), $container);

        self::assertSame(1000, $GLOBALS['EXEC_TIME']);
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    #[Test]
    public function beforeRequestRefreshesTheClockWithoutTouchingTheContainer(): void
    {
        $container = WorkerTestContainer::create();
        $GLOBALS['EXEC_TIME'] = 1000;

        (new WorkerStateResetter())->beforeRequest($container);

        self::assertEqualsWithDelta(time(), $GLOBALS['EXEC_TIME'], 2);
        self::assertSame($GLOBALS['EXEC_TIME'] - $GLOBALS['EXEC_TIME'] % 60, $GLOBALS['ACCESS_TIME']);
        self::assertTrue($container->initialized(WorkerTestContainer::DISCARDED));
    }

    #[Test]
    public function resetIsBothPhasesInOrder(): void
    {
        $container = WorkerTestContainer::create();
        $resetter = new WorkerStateResetter();
        $GLOBALS['EXEC_TIME'] = 1000;

        $discarded = $resetter->reset($resetter->capture($container), $container);

        self::assertSame(1, $discarded);
        self::assertEqualsWithDelta(time(), $GLOBALS['EXEC_TIME'], 2);
    }

    #[Test]
    public function captureWithoutKeepParameterDisablesDiscarding(): void
    {
        $container = new Container();
        $resetter = new WorkerStateResetter();
        $container->set(Context::class, new Context());
        $container->set(\TYPO3\CMS\Core\Page\AssetCollector::class, new \TYPO3\CMS\Core\Page\AssetCollector());
        $container->set(\TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry::class, new \TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry());
        $container->set('test.service', new \stdClass());

        $discarded = @$resetter->afterResponse($resetter->capture($container), $container);

        self::assertSame(0, $discarded);
        self::assertTrue($container->initialized('test.service'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function runtimeCacheKeys(): iterable
    {
        yield 'labels' => ['labels_core_locallang', true];
        yield 'file labels' => ['labels_file_abc', true];
        yield 'table info, SQLite (no dbname)' => ['generic_0af1b2c3d4e5f607-tableinfo-cache_pages', true];
        yield 'table info, MySQL' => ['typo3_0af1b2c3d4e5f607-tableinfo-pages', true];
        yield 'table names' => ['typo3_0af1b2c3d4e5f607-tablenames', true];
        yield 'rootline' => ['rootline-localcache-1_0_0_0', false];
        yield 'page row' => ['PageRepository_getPage_0af1b2c3', false];
        yield 'looks like table info but is a record cache' => ['ContentFetcher_tableinfo-1', false];
    }

    #[Test]
    #[DataProvider('runtimeCacheKeys')]
    public function runtimeCacheKeepPolicy(string $key, bool $kept): void
    {
        self::assertSame($kept, WorkerStateResetter::keepsRuntimeCacheEntry($key));
    }
}
