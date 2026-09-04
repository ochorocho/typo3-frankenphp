<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

/**
 * Requires the located configuration files and merges them in order.
 */
final class WorkerConfigurationLoader
{
    /**
     * @param array<string, string> $files origin => absolute path
     */
    public function load(array $files): WorkerConfiguration
    {
        $configuration = new WorkerConfiguration();
        foreach ($files as $origin => $file) {
            $configuration = $configuration->merge(
                WorkerConfiguration::fromArray(self::requireFile($file), $origin, $file)
            );
        }
        return $configuration;
    }

    private static function requireFile(string $file): mixed
    {
        return require $file;
    }
}
