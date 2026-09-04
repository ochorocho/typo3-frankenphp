<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Worker\WorkerConfigurationFileLocator;
use Ochorocho\FrankenPhp\Worker\WorkerConfigurationLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerConfigurationFileLocatorTest extends TestCase
{
    private const string PACKAGES = __DIR__ . '/Fixtures/packages/';
    private const string CONFIG = __DIR__ . '/Fixtures/config';

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
