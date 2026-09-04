<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Worker\WorkerConfigurationLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerConfigurationLoaderTest extends TestCase
{
    #[Test]
    public function loadsAndMergesFilesInOrder(): void
    {
        $configuration = (new WorkerConfigurationLoader())->load([
            'ext_a' => __DIR__ . '/Fixtures/valid.php',
            'ext_b' => __DIR__ . '/Fixtures/second.php',
        ]);

        self::assertSame(['Vendor\\Ext\\PinnedRegistry' => 'ext_a'], $configuration->pinned);
        self::assertSame(['Vendor\\Ext\\Stateless' => 'ext_a'], $configuration->keep);
        self::assertSame(
            ['Vendor\\Ext\\PerRequest' => 'ext_a', 'vendor.ext.legacy' => 'ext_a', 'Vendor\\Ext\\Stateless' => 'ext_b'],
            $configuration->discard
        );
        self::assertSame(['/^Vendor\\\\Ext\\\\Controller\\\\/' => 'ext_a'], $configuration->discardPatterns);
    }

    #[Test]
    public function noFilesYieldsEmptyConfiguration(): void
    {
        self::assertTrue((new WorkerConfigurationLoader())->load([])->isEmpty());
    }

    #[Test]
    public function fileReturningNonArrayNamesTheFile(): void
    {
        $file = __DIR__ . '/Fixtures/returns-string.php';
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($file . ' must return an array, string returned.');

        (new WorkerConfigurationLoader())->load(['ext_a' => $file]);
    }

    #[Test]
    public function fileWithUnknownKeyNamesTheFile(): void
    {
        $file = __DIR__ . '/Fixtures/unknown-key.php';
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($file . ': unknown key(s) "pinned"');

        (new WorkerConfigurationLoader())->load(['ext_a' => $file]);
    }
}
