<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Cache;

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;

/**
 * PhpFrontend with an in-process memo of require() results.
 *
 * Core stores TypoScript and TSconfig trees as `return unserialize('...')`
 * PHP files; OPcache caches the compiled file, but the unserialize() runs on
 * every request. In a worker the process lives on, so the unserialized value
 * can be kept: the memo is validated by the file's modification time, so a
 * cache flush or rewrite in any process is seen on the next request, and it
 * costs one stat where the backend already paid one for file_exists().
 *
 * Entry identifiers of these caches are hashes of their inputs, so a memoised
 * value can never belong to different content under the same identifier.
 * Registered for the `typoscript` cache by ext_localconf.php under FrankenPHP.
 */
class WorkerPhpFrontend extends PhpFrontend
{
    public const int MAX_ENTRIES = 64;

    /** @var array<string, array{int, mixed}> identifier => [mtime, value] */
    private array $memo = [];

    public function require(string $entryIdentifier): mixed
    {
        $backend = $this->backend;
        if (!$backend instanceof SimpleFileBackend) {
            return parent::require($entryIdentifier);
        }
        $file = $backend->getCacheDirectory() . $entryIdentifier . '.php';
        $mtime = @filemtime($file);
        if ($mtime === false) {
            unset($this->memo[$entryIdentifier]);
            return false;
        }
        if (isset($this->memo[$entryIdentifier]) && $this->memo[$entryIdentifier][0] === $mtime) {
            return $this->memo[$entryIdentifier][1];
        }
        $value = parent::require($entryIdentifier);
        if ($value !== false) {
            unset($this->memo[$entryIdentifier]);
            if (count($this->memo) >= self::MAX_ENTRIES) {
                array_shift($this->memo);
            }
            $this->memo[$entryIdentifier] = [$mtime, $value];
        }
        return $value;
    }

    public function set(string $entryIdentifier, mixed $data, array $tags = [], ?int $lifetime = null): void
    {
        unset($this->memo[$entryIdentifier]);
        parent::set($entryIdentifier, $data, $tags, $lifetime);
    }

    public function remove(string $entryIdentifier): bool
    {
        unset($this->memo[$entryIdentifier]);
        return parent::remove($entryIdentifier);
    }

    public function flush(): void
    {
        $this->memo = [];
        parent::flush();
    }

    public function memoSize(): int
    {
        return count($this->memo);
    }
}
