<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Finds Configuration/FrankenPhpWorker.php in every active package and the
 * project-level config/system/frankenphp-worker.php.
 *
 * Runs while the DI container is being compiled. At that point the
 * PackageManager only exists as a synthetic "_early.*" definition and TYPO3
 * sets no container parameters, so a throwaway PackageManager is built the
 * same way Bootstrap::init() builds the real one. Cost: one composer.json
 * parse per package, once per container build.
 */
final class WorkerConfigurationFileLocator
{
    public const string PACKAGE_FILE = 'Configuration/FrankenPhpWorker.php';
    public const string PROJECT_FILE = 'system/frankenphp-worker.php';
    public const string PROJECT_ORIGIN = 'project';

    /**
     * @param (\Closure(): PackageManager)|null $packageManagerFactory defaults to the Bootstrap helpers
     */
    public function __construct(
        private readonly ?\Closure $packageManagerFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return array<string, string> origin => absolute path, packages in dependency order, project last
     */
    public function locate(): array
    {
        return $this->locateIn($this->packagePaths(), Environment::getConfigPath());
    }

    /**
     * @param array<string, string> $packagePaths package key => package path with trailing slash
     * @return array<string, string> origin => absolute path
     */
    public function locateIn(array $packagePaths, string $configPath): array
    {
        $files = [];
        foreach ($packagePaths as $packageKey => $packagePath) {
            $file = rtrim($packagePath, '/') . '/' . self::PACKAGE_FILE;
            if (is_file($file)) {
                $files[$packageKey] = $file;
            }
        }
        $projectFile = rtrim($configPath, '/') . '/' . self::PROJECT_FILE;
        if (is_file($projectFile)) {
            $files[self::PROJECT_ORIGIN] = $projectFile;
        }
        return $files;
    }

    /**
     * @return array<string, string> package key => package path
     */
    private function packagePaths(): array
    {
        try {
            $packageManager = ($this->packageManagerFactory ?? self::createPackageManager(...))();
            $paths = [];
            foreach ($packageManager->getActivePackages() as $package) {
                $paths[$package->getPackageKey()] = $package->getPackagePath();
            }
            return $paths;
        } catch (\Throwable $exception) {
            // The Bootstrap helpers are @internal and may change between Core
            // releases. Booting with the default classification beats taking
            // the site down, but the package files are then unused: say so.
            $this->logger->error(
                'Cannot enumerate packages, every {file} is ignored until this is fixed: {error}',
                ['file' => self::PACKAGE_FILE, 'error' => $exception->getMessage(), 'exception' => $exception]
            );
            return [];
        }
    }

    private static function createPackageManager(): PackageManager
    {
        // NullFrontend: the package cache only matters in classic mode and
        // must not write into the real core cache from inside a compile.
        return Bootstrap::createPackageManager(
            PackageManager::class,
            Bootstrap::createPackageCache(new NullFrontend('core'))
        );
    }
}
