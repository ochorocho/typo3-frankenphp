<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

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
     * @return array<string, string> origin => absolute path, packages in dependency order, project last
     */
    public function locate(): array
    {
        $packagePaths = [];
        foreach ($this->createPackageManager()->getActivePackages() as $package) {
            $packagePaths[$package->getPackageKey()] = $package->getPackagePath();
        }
        return $this->locateIn($packagePaths, Environment::getConfigPath());
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

    private function createPackageManager(): PackageManager
    {
        // NullFrontend: the package cache only matters in classic mode and
        // must not write into the real core cache from inside a compile.
        return Bootstrap::createPackageManager(
            PackageManager::class,
            Bootstrap::createPackageCache(new NullFrontend('core'))
        );
    }
}
