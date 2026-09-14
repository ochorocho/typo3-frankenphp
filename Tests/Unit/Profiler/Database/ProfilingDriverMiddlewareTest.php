<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Profiler\Database;

use Doctrine\DBAL\Driver;
use Ochorocho\FrankenPhp\Profiler\Database\ProfilingDriver;
use Ochorocho\FrankenPhp\Profiler\Database\ProfilingDriverMiddleware;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * ConnectionPool builds driver middlewares with GeneralUtility::makeInstance(),
 * which only asks the container when one is set and knows the class. In a
 * failsafe bootstrap (`typo3 setup`) neither holds, so the middleware must stay
 * constructible without arguments.
 */
final class ProfilingDriverMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function isConstructibleWithoutAContainer(): void
    {
        GeneralUtility::purgeInstances();

        $middleware = new ProfilingDriverMiddleware();

        self::assertInstanceOf(ProfilingDriver::class, $middleware->wrap(self::createStub(Driver::class)));
    }

    #[Test]
    public function isConstructibleWhenTheContainerDoesNotKnowTheCollector(): void
    {
        $container = self::createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        GeneralUtility::setContainer($container);

        $middleware = new ProfilingDriverMiddleware();

        self::assertInstanceOf(ProfilingDriver::class, $middleware->wrap(self::createStub(Driver::class)));
    }

    #[Test]
    public function usesTheCollectorFromTheContainerWhenAvailable(): void
    {
        $profile = new RequestProfile();
        $container = self::createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === RequestProfile::class
        );
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($profile): RequestProfile {
                self::assertSame(RequestProfile::class, $id);
                return $profile;
            }
        );
        GeneralUtility::setContainer($container);

        $middleware = new ProfilingDriverMiddleware();

        self::assertSame($profile, $this->profileOf($middleware->wrap(self::createStub(Driver::class))));
    }

    #[Test]
    public function prefersTheInjectedCollectorOverTheContainer(): void
    {
        $injected = new RequestProfile();
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        GeneralUtility::setContainer($container);

        $middleware = new ProfilingDriverMiddleware($injected);

        self::assertSame($injected, $this->profileOf($middleware->wrap(self::createStub(Driver::class))));
    }

    private function profileOf(Driver $driver): RequestProfile
    {
        $profile = (new \ReflectionProperty(ProfilingDriver::class, 'profile'))->getValue($driver);
        self::assertInstanceOf(RequestProfile::class, $profile);
        return $profile;
    }
}
