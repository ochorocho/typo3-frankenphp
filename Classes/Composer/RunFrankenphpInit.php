<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Composer;

use Composer\Script\Event;
use TYPO3\CMS\Composer\Plugin\Core\InstallerScript;

/**
 * Composer-installer-script hook that runs `vendor/bin/typo3 frankenphp:init
 * --no-interaction` after the package is installed/updated. The CLI command
 * is the single source of truth for generating Caddyfile, .env, and
 * public/worker.php; this hook just ensures the user gets those files
 * without having to remember to run the command themselves.
 *
 * `--no-interaction` skips files that already exist (the CLI's
 * fileShouldBeCreated gate), so a `composer update` won't clobber a user's
 * hand-edited config. Pass `--force` manually to overwrite.
 */
final class RunFrankenphpInit implements InstallerScript
{
    public function run(Event $event): bool
    {
        // Only a TYPO3 project root gets the generated infrastructure. When
        // the root package is an extension (this extension's own checkout,
        // or another extension that depends on it for its dev setup) there is
        // nothing to configure, and Caddyfile / .env / public/worker.php
        // would land in the extension source tree.
        if ($event->getComposer()->getPackage()->getType() !== 'typo3-cms-project') {
            $event->getIO()->write(
                'ochorocho/frankenphp: root package is not a typo3-cms-project, skipping frankenphp:init auto-run.',
                true,
                \Composer\IO\IOInterface::VERBOSE
            );
            return true;
        }

        $vendorDir = (string)$event->getComposer()->getConfig()->get('vendor-dir');
        $binary = $vendorDir . '/bin/typo3';
        if (!is_executable($binary)) {
            $event->getIO()->writeError(sprintf(
                '<warning>ochorocho/frankenphp: %s not found; '
                . 'skipping frankenphp:init auto-run. Run it manually after install.</warning>',
                $binary
            ));
            return true;
        }
        $cmd = escapeshellarg($binary) . ' frankenphp:init --no-interaction';
        $event->getIO()->write('> ' . $cmd);
        $exitCode = 0;
        passthru($cmd, $exitCode);
        return $exitCode === 0;
    }
}
