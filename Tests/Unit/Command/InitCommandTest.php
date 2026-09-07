<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Command;

use Ochorocho\FrankenPhp\Command\InitCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class InitCommandTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/frankenphp-init-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/public', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->project . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        array_map(unlink(...), glob($this->project . '/public/*') ?: []);
        rmdir($this->project . '/public');
        rmdir($this->project);
    }

    #[Test]
    public function profileFollowsTheProductionContextWhenNotGiven(): void
    {
        $this->init('Production');

        self::assertStringContainsString('TYPO3_CONTEXT=Production', $this->generated('.env'));
        self::assertStringContainsString('admin off', $this->generated('Caddyfile'));
        self::assertStringNotContainsString("\tdebug\n", $this->generated('Caddyfile'));
        self::assertStringNotContainsString('E_STRICT', $this->generated('php.ini'));
        self::assertDoesNotMatchRegularExpression('/^opcache\.jit/m', $this->generated('php.ini'));
        self::assertMatchesRegularExpression('/^FRANKENPHP_WORKER_COUNT=([4-9]|[1-9][0-9]+)$/m', $this->generated('.env'));
        // The Caddyfile placeholder defaults mirror .env: Caddy only reads .env with --envfile.
        preg_match('/^FRANKENPHP_WORKER_COUNT=(\\d+)$/m', $this->generated('.env'), $m);
        self::assertStringContainsString('{$FRANKENPHP_WORKER_COUNT:' . $m[1] . '}', $this->generated('Caddyfile'));
        $numThreads = (int)$m[1] + 2;
        self::assertStringContainsString("FRANKENPHP_NUM_THREADS={$numThreads}\n", $this->generated('.env'));
        self::assertStringContainsString('num_threads {$FRANKENPHP_NUM_THREADS:' . $numThreads . '}', $this->generated('Caddyfile'));
        self::assertStringContainsString("\t\t# max_threads auto\n\t\t# max_wait_time 30s\n", $this->generated('Caddyfile'));
        self::assertStringContainsString("MAX_MEMORY_MB=0\n", $this->generated('.env'));
        self::assertStringContainsString("#GOGC=200\n", $this->generated('.env'));
        self::assertStringContainsString("#FRANKENPHP_PROFILE=1\n", $this->generated('.env'));
        self::assertStringContainsString('{$HTTP_PORT:80}', $this->generated('Caddyfile'));
        self::assertStringContainsString('{$HTTPS_PORT:443}', $this->generated('Caddyfile'));
        self::assertStringContainsString('MAX_REQUESTS=10000', $this->generated('.env'));
    }

    #[Test]
    public function profileDefaultsToDevOutsideProduction(): void
    {
        $this->init('Development');

        self::assertStringContainsString('TYPO3_CONTEXT=Development', $this->generated('.env'));
        self::assertStringContainsString("\tdebug\n", $this->generated('Caddyfile'));
        self::assertStringNotContainsString('admin off', $this->generated('Caddyfile'));
        self::assertMatchesRegularExpression('/^FRANKENPHP_WORKER_COUNT=([2-9]|[1-9][0-9]+)$/m', $this->generated('.env'));
        self::assertStringContainsString('{$HTTPS_PORT:8885}', $this->generated('Caddyfile'));
    }

    #[Test]
    public function explicitProfileWinsOverTheContext(): void
    {
        $this->init('Development', ['--profile' => 'prod']);

        self::assertStringContainsString('TYPO3_CONTEXT=Production', $this->generated('.env'));
    }

    #[Test]
    public function prometheusKeepsTheAdminEndpointInProduction(): void
    {
        $this->init('Production', ['--prometheus' => true]);

        $caddyfile = $this->generated('Caddyfile');
        self::assertStringNotContainsString('admin off', $caddyfile);
        self::assertStringContainsString('admin localhost:{$METRICS_PORT:2019}', $caddyfile);
    }

    #[Test]
    public function caddyfileDeniesHtaccessProtectedPathsAndRedactsTokens(): void
    {
        foreach (['Production', 'Development'] as $context) {
            $this->init($context, ['--force' => true]);
            $caddyfile = $this->generated('Caddyfile');

            self::assertStringContainsString("handle @denied {\n\t\trespond 403\n\t}", $caddyfile);
            self::assertStringContainsString('not path /.well-known/*', $caddyfile);
            self::assertStringContainsString('/_(?:recycler|temp)_/', $caddyfile);
            self::assertStringContainsString('replace token REDACTED', $caddyfile);
        }
    }

    #[Test]
    public function caddyfileServesAssetsFromCaddyAndRoutesEverythingElseToTheWorkerWithoutAStat(): void
    {
        foreach (['Production', 'Development'] as $context) {
            $this->init($context, ['--force' => true]);
            $caddyfile = $this->generated('Caddyfile');

            self::assertStringContainsString('path /_assets/* /_assets_install/* /fileadmin/* /typo3temp/assets/* /favicon.ico /robots.txt', $caddyfile);
            self::assertStringContainsString("not path */\n", $caddyfile, 'directories fall through to TYPO3');
            self::assertStringContainsString('precompressed zstd br gzip', $caddyfile);
            self::assertStringContainsString('Cache-Control "public, max-age=31536000"', $caddyfile, 'CSS and JS: one year, as TYPO3\'s .htaccess');
            self::assertStringContainsString('Cache-Control "public, max-age=2592000"', $caddyfile, 'other assets: one month');
            self::assertStringContainsString("\t\trewrite * /worker.php\n\t\tphp\n", $caddyfile);
            self::assertStringNotContainsString('try_files', $caddyfile);
            self::assertStringNotContainsString('php_server {', $caddyfile);
            // The routing tail must stay behind the deny and install-tool handles.
            self::assertGreaterThan(strpos($caddyfile, 'handle @typo3_install'), strpos($caddyfile, 'rewrite * /worker.php'));
        }
    }

    #[Test]
    public function forceBacksUpAnExistingEnvFile(): void
    {
        file_put_contents($this->project . '/.env', "DB_PASSWORD=secret\n");

        $this->init('Development', ['--force' => true]);

        $backups = glob($this->project . '/.env.bak-*') ?: [];
        self::assertCount(1, $backups);
        self::assertSame("DB_PASSWORD=secret\n", file_get_contents($backups[0]));
        self::assertStringNotContainsString('DB_PASSWORD', $this->generated('.env'));
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function init(string $context, array $options = []): void
    {
        Environment::initialize(
            new ApplicationContext($context),
            true,
            true,
            $this->project,
            $this->project . '/public',
            $this->project . '/var',
            $this->project . '/config',
            $this->project . '/public/index.php',
            'UNIX'
        );
        $command = new InitCommand();
        (new Application())->add($command);
        $tester = new CommandTester($command);
        $tester->execute($options, ['interactive' => false]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('--envfile .env', $tester->getDisplay());
    }

    private function generated(string $file): string
    {
        return (string)file_get_contents($this->project . '/' . $file);
    }
}
