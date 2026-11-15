<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Command;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Caddy modules compiled into the local frankenphp binary.
 *
 * Builds differ (Homebrew ships without vulcain), and Caddy refuses a
 * Caddyfile that orders an unknown directive.
 */
final readonly class FrankenPhpModules
{
    public function __construct(
        private ?string $binary = null,
    ) {}

    /**
     * @return list<string>|null module ids, or null when the binary cannot be asked
     */
    public function names(): ?array
    {
        $binary = $this->binary ?? (new ExecutableFinder())->find('frankenphp');
        if ($binary === null) {
            return null;
        }
        $process = new Process([$binary, 'list-modules']);
        $process->setTimeout(10);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }
        if (!$process->isSuccessful()) {
            return null;
        }
        return array_values(array_filter(array_map(trim(...), explode("\n", $process->getOutput()))));
    }
}
