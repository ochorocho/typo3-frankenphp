<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Tests\Unit\Worker\Fixtures\WorkerTestContainer;
use Ochorocho\FrankenPhp\Worker\RuntimeCacheInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

final class RuntimeCacheInventoryTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'runtime-cache-');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/camino/';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    #[Test]
    public function recordsOneLinePerRuntimeCacheEntry(): void
    {
        $container = WorkerTestContainer::create();
        $runtimeCache = $container->get('cache.runtime');
        self::assertInstanceOf(FrontendInterface::class, $runtimeCache);
        $runtimeCache->set('labels_core', ['a' => 'b']);
        $runtimeCache->set('rootline-localcache-1_0_0_0', 'x');

        (new RuntimeCacheInventory($this->logFile))->record($container);

        $lines = explode("\n", trim((string)file_get_contents($this->logFile)));
        self::assertCount(2, $lines);
        self::assertSame("GET /camino/\tlabels_core\tarray\t" . strlen(serialize(['a' => 'b'])), $lines[0]);
        self::assertStringStartsWith("GET /camino/\trootline-localcache-1_0_0_0\tstring\t", $lines[1]);
    }

    #[Test]
    public function summarizeGroupsByKeyShapeAndCountsRequests(): void
    {
        $log = "GET /a\trootline-localcache-1_0_0_0\tarray\t100\n"
            . "GET /a\trootline-localcache-2_0_0_0\tarray\t50\n"
            . "GET /b\trootline-localcache-3_0_0_0\tarray\t50\n"
            . "GET /b\tlabels_core\tarray\t10\n"
            . "broken line without tabs\n";

        $groups = RuntimeCacheInventory::summarize($log);

        self::assertSame(['rootline-localcache-<n>_<n>_<n>_<n>', 'labels_core'], array_keys($groups));
        self::assertSame(['count' => 3, 'requests' => 2, 'bytes' => 200, 'example' => 'rootline-localcache-1_0_0_0'], $groups['rootline-localcache-<n>_<n>_<n>_<n>']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function keys(): iterable
    {
        yield 'md5' => ['PageRepository_getPage_' . str_repeat('a1', 16), 'PageRepository_getPage_<md5>'];
        yield 'xxh3' => ['pageTsConfig-hash-to-object-0af1b2c3d4e5f607', 'pageTsConfig-hash-to-object-<xxh3>'];
        yield 'numbers' => ['shortcuts_resolved_1_12_0_0', 'shortcuts_resolved_<n>_<n>_<n>_<n>'];
        yield 'table info' => ['typo3_0af1b2c3d4e5f607-tableinfo-pages', 'typo3_<xxh3>-tableinfo-pages'];
        yield 'plain' => ['labels_core', 'labels_core'];
    }

    #[Test]
    #[DataProvider('keys')]
    public function normalizesHashesAndNumbers(string $key, string $shape): void
    {
        self::assertSame($shape, RuntimeCacheInventory::normalizeKey($key));
    }
}
