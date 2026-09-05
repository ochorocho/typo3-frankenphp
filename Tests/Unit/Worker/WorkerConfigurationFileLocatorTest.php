<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Worker\WorkerConfigurationFileLocator;
use Ochorocho\FrankenPhp\Worker\WorkerConfigurationLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;

final class WorkerConfigurationFileLocatorTest extends TestCase
{
    private const string PACKAGES = __DIR__ . '/Fixtures/packages/';
    private const string CONFIG = __DIR__ . '/Fixtures/config';

    #[Test]
    public function locateAsksThePackageManagerAndAddsTheProjectFile(): void
    {
        $this->initializeEnvironment();
        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([
            'ext_with_file' => $this->package('ext_with_file', self::PACKAGES . 'ext_with_file/'),
            'ext_without_file' => $this->package('ext_without_file', self::PACKAGES . 'ext_without_file/'),
        ]);

        $files = (new WorkerConfigurationFileLocator(static fn(): PackageManager => $packageManager))->locate();

        self::assertSame([
            'ext_with_file' => self::PACKAGES . 'ext_with_file/' . WorkerConfigurationFileLocator::PACKAGE_FILE,
            WorkerConfigurationFileLocator::PROJECT_ORIGIN => self::CONFIG . '/' . WorkerConfigurationFileLocator::PROJECT_FILE,
        ], $files);
    }

    #[Test]
    public function locateLogsAndKeepsTheProjectFileWhenPackageEnumerationFails(): void
    {
        $this->initializeEnvironment();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(self::stringContains('Cannot enumerate packages'), self::callback(
                static fn(array $context): bool => $context['error'] === 'Core API changed'
            ));
        $locator = new WorkerConfigurationFileLocator(
            static fn(): PackageManager => throw new \RuntimeException('Core API changed'),
            $logger
        );

        self::assertSame(
            [WorkerConfigurationFileLocator::PROJECT_ORIGIN => self::CONFIG . '/' . WorkerConfigurationFileLocator::PROJECT_FILE],
            $locator->locate()
        );
    }

    private function initializeEnvironment(): void
    {
        $project = dirname(self::CONFIG);
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $project,
            $project . '/public',
            $project . '/var',
            self::CONFIG,
            $project . '/public/index.php',
            'UNIX'
        );
    }

    private function package(string $key, string $path): PackageInterface
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackageKey')->willReturn($key);
        $package->method('getPackagePath')->willReturn($path);
        return $package;
    }

    #[Test]
    public function findsPackageFilesKeyedByPackageKeyAndProjectFileLast(): void
    {
        $files = (new WorkerConfigurationFileLocator())->locateIn([
            'ext_with_file' => self::PACKAGES . 'ext_with_file/',
            'ext_without_file' => self::PACKAGES . 'ext_without_file/',
        ], self::CONFIG);

        self::assertSame([
            'ext_with_file' => self::PACKAGES . 'ext_with_file/' . WorkerConfigurationFileLocator::PACKAGE_FILE,
            WorkerConfigurationFileLocator::PROJECT_ORIGIN => self::CONFIG . '/' . WorkerConfigurationFileLocator::PROJECT_FILE,
        ], $files);
    }

    #[Test]
    public function toleratesMissingTrailingSlashes(): void
    {
        $files = (new WorkerConfigurationFileLocator())->locateIn(
            ['ext_with_file' => self::PACKAGES . 'ext_with_file'],
            self::CONFIG . '/'
        );

        self::assertCount(2, $files);
    }

    #[Test]
    public function noPackagesAndNoProjectFileYieldsNothing(): void
    {
        self::assertSame([], (new WorkerConfigurationFileLocator())->locateIn([], self::PACKAGES . 'ext_without_file'));
    }

    #[Test]
    public function locatedFilesLoadWithTheProjectOverridingPackages(): void
    {
        $files = (new WorkerConfigurationFileLocator())->locateIn(
            ['ext_with_file' => self::PACKAGES . 'ext_with_file/'],
            self::CONFIG
        );
        $configuration = (new WorkerConfigurationLoader())->load($files);

        self::assertSame(['Vendor\\ExtWithFile\\PerRequest' => 'ext_with_file'], $configuration->discard);
        self::assertSame(['Vendor\\ExtWithFile\\PerRequest' => WorkerConfigurationFileLocator::PROJECT_ORIGIN], $configuration->keep);
    }
}
