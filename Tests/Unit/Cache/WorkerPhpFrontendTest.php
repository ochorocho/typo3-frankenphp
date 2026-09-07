<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Cache;

use Ochorocho\FrankenPhp\Cache\WorkerPhpFrontend;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class WorkerPhpFrontendTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // SystemEnvironmentBuilder defines LF in a booted TYPO3; PhpFrontend::set() uses it.
        defined('LF') || define('LF', "\n");
    }

    private string $directory;
    private WorkerPhpFrontend $cache;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/frankenphp-phpfrontend-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/var/cache/code', 0775, true);
        Environment::initialize(new ApplicationContext('Testing'), true, true, $this->directory, $this->directory . '/public', $this->directory . '/var', $this->directory . '/config', $this->directory . '/public/index.php', 'UNIX');
        $backend = new SimpleFileBackend();
        $this->cache = new WorkerPhpFrontend('typoscript', $backend);
        $backend->setCache($this->cache);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function removeDirectory(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function file(string $identifier): string
    {
        return $this->directory . '/var/cache/code/typoscript/' . $identifier . '.php';
    }

    #[Test]
    public function memoisesTheRequiredValueWhileTheFileIsUnchanged(): void
    {
        $this->cache->set('setup-abc', 'return unserialize(\'' . addcslashes(serialize(['ast' => ['a' => 1]]), '\'\\') . '\');');

        $first = $this->cache->require('setup-abc');
        $second = $this->cache->require('setup-abc');

        self::assertSame(['ast' => ['a' => 1]], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $this->cache->memoSize());
    }

    #[Test]
    public function aRewrittenFileWithANewerMtimeIsReadAgain(): void
    {
        $this->cache->set('setup-abc', 'return 1;');
        self::assertSame(1, $this->cache->require('setup-abc'));

        // A cache:flush plus rebuild in another process: same identifier, new file.
        file_put_contents($this->file('setup-abc'), "<?php\nreturn 2;");
        touch($this->file('setup-abc'), time() + 10);

        self::assertSame(2, $this->cache->require('setup-abc'));
    }

    #[Test]
    public function aRemovedFileIsAMissAndDropsTheMemo(): void
    {
        $this->cache->set('setup-abc', 'return 1;');
        $this->cache->require('setup-abc');

        unlink($this->file('setup-abc'));

        self::assertFalse($this->cache->require('setup-abc'));
        self::assertSame(0, $this->cache->memoSize());
    }

    #[Test]
    public function setRemoveAndFlushDropTheMemo(): void
    {
        $this->cache->set('a', 'return 1;');
        $this->cache->set('b', 'return 1;');
        $this->cache->require('a');
        $this->cache->require('b');
        self::assertSame(2, $this->cache->memoSize());

        $this->cache->set('a', 'return 2;');
        self::assertSame(1, $this->cache->memoSize());
        self::assertSame(2, $this->cache->require('a'));

        $this->cache->remove('b');
        self::assertSame(1, $this->cache->memoSize());

        $this->cache->flush();
        self::assertSame(0, $this->cache->memoSize());
    }

    #[Test]
    public function theMemoIsBounded(): void
    {
        for ($i = 0; $i < WorkerPhpFrontend::MAX_ENTRIES + 5; $i++) {
            $this->cache->set('entry-' . $i, 'return ' . $i . ';');
            $this->cache->require('entry-' . $i);
        }

        self::assertSame(WorkerPhpFrontend::MAX_ENTRIES, $this->cache->memoSize());
        self::assertSame(WorkerPhpFrontend::MAX_ENTRIES + 4, $this->cache->require('entry-' . (WorkerPhpFrontend::MAX_ENTRIES + 4)));
    }
}
