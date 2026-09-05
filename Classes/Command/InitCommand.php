<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

#[AsCommand(
    name: 'frankenphp:init',
    description: 'Initialize FrankenPHP configuration (Caddyfile, .env, php.ini, worker.php)',
)]
class InitCommand extends Command
{
    private const string PROFILE_DEV = 'dev';
    private const string PROFILE_PROD = 'prod';

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite Caddyfile, .env, php.ini and worker.php if they already exist.'
        );
        $this->addOption(
            'profile',
            null,
            InputOption::VALUE_REQUIRED,
            'Configuration profile: dev or prod. Drives Caddyfile / .env / php.ini / worker.php defaults. '
            . 'Default: prod when TYPO3_CONTEXT is Production, dev otherwise.'
        );
        $this->addOption(
            'prometheus',
            null,
            InputOption::VALUE_NONE,
            'Enable Caddy + FrankenPHP Prometheus metrics. Adds `metrics` + an `admin localhost:METRICS_PORT` '
            . 'directive to the global Caddyfile block; the port (default 2019) is prompted during init '
            . 'and stored as METRICS_PORT in .env. The endpoint is localhost-bound and meant for server-side '
            . 'scrapers (Prometheus, Grafana Agent, curl) — browsers cannot reach it directly because Caddy '
            . 'admin rejects requests with no Origin header.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $profile = $this->resolveProfile($input, $io);
        if ($profile === null) {
            return Command::FAILURE;
        }
        $isProd = $profile === self::PROFILE_PROD;
        $prometheus = (bool)$input->getOption('prometheus');

        $projectPath = Environment::getProjectPath();
        $caddyFilePath = $projectPath . '/Caddyfile';

        $envFilePath = $projectPath . '/.env';

        $force = (bool)$input->getOption('force');

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $root = str_replace('//', '/', $this->deriveWebDir($projectPath));

        // Profile-aware prompt defaults. The --profile flag is the single
        // source of truth; if a user wants a Production TYPO3_CONTEXT with
        // dev ports they can still override interactively or via .env later.
        $defaults = $isProd
            ? ['httpPort' => 80, 'httpsPort' => 443, 'context' => 'Production',
                'workerCount' => '4', 'maxRequests' => '1000']
            : ['httpPort' => 8888, 'httpsPort' => 8885, 'context' => 'Development',
                'workerCount' => '2', 'maxRequests' => '500'];

        if ($input->isInteractive()) {
            $io->block(sprintf('Set environment variables (.env) for profile "%s":', $profile), 'INFO', 'fg=green', '');
        }

        $httpPort = $this->askPort($helper, $input, $output, 'HTTP_PORT', $defaults['httpPort']);
        $httpsPort = $this->askPort($helper, $input, $output, 'HTTPS_PORT', $defaults['httpsPort']);

        // Prompted only when --prometheus is set, so users on the default
        // (metrics-off) path don't see an unrelated port question. Caddy's
        // admin endpoint defaults to localhost:2019, so 2019 keeps the
        // out-of-the-box behavior unless the user picks a different port
        // to avoid a conflict.
        $metricsPort = $prometheus
            ? $this->askPort($helper, $input, $output, 'METRICS_PORT', 2019)
            : null;

        $phpIniScanDir = (string)$helper->ask($input, $output, new Question(
            'PHP_INI_SCAN_DIR [<info>./</info>]: ',
            './'
        ));

        $typo3Context = (string)$helper->ask($input, $output, new Question(
            sprintf('TYPO3_CONTEXT [<info>%s</info>]: ', $defaults['context']),
            $defaults['context']
        ));

        $serverName = (string)$helper->ask($input, $output, new Question(
            'SERVER_NAME [<info>localhost</info>]: ',
            'localhost'
        ));

        $workerMode = (bool)$helper->ask($input, $output, new ConfirmationQuestion(
            'Enable FrankenPHP Worker Mode? [<info>yes</info>]: ',
            true
        ));

        $workerCount = null;
        $maxRequests = null;
        if ($workerMode) {
            $workerCount = (string)$helper->ask($input, $output, new Question(
                sprintf('FRANKENPHP_WORKER_COUNT [<info>%s</info>]: ', $defaults['workerCount']),
                $defaults['workerCount']
            ));

            $maxRequests = (string)$helper->ask($input, $output, new Question(
                sprintf('MAX_REQUESTS [<info>%s</info>]: ', $defaults['maxRequests']),
                $defaults['maxRequests']
            ));
        }

        if ($this->fileShouldBeCreated($caddyFilePath, $io, $force)) {
            $caddyFileContent = $this->buildCaddyfile($root, $workerMode, $isProd, $prometheus);
            file_put_contents($caddyFilePath, $caddyFileContent);
            $io->success('Created Caddyfile');
        }

        if ($this->fileShouldBeCreated($envFilePath, $io, $force)) {
            // .env is the one generated file users extend by hand (dotenv
            // connector credentials); never let --force destroy that silently.
            if (file_exists($envFilePath)) {
                $backup = $envFilePath . '.bak-' . date('Ymd-His');
                copy($envFilePath, $backup);
                $io->note(sprintf('Existing .env backed up to %s', $backup));
            }
            $envContent = $this->buildEnvFile(
                $phpIniScanDir,
                $typo3Context,
                $serverName,
                $httpPort,
                $httpsPort,
                $workerCount,
                $maxRequests,
                $metricsPort,
            );
            file_put_contents($envFilePath, $envContent);
            $io->success('Created .env');
        }

        $phpIniPath = $this->resolvePhpIniPath($projectPath, $phpIniScanDir);
        if ($this->fileShouldBeCreated($phpIniPath, $io, $force)) {
            $phpIniDir = dirname($phpIniPath);
            if (!is_dir($phpIniDir) && !@mkdir($phpIniDir, 0775, true) && !is_dir($phpIniDir)) {
                $io->warning(sprintf('Could not create PHP_INI_SCAN_DIR "%s" for php.ini.', $phpIniDir));
            } else {
                file_put_contents($phpIniPath, $this->buildPhpIni($typo3Context, $isProd));
                $io->success('Created ' . $phpIniPath);
            }
        }

        $workerFilePath = Environment::getPublicPath() . '/worker.php';
        if ($workerMode && $this->fileShouldBeCreated($workerFilePath, $io, $force)) {
            $workerContent = $this->buildWorkerPhp($workerFilePath, $projectPath);
            if ($workerContent === null) {
                $io->warning('Could not generate worker.php — template file missing.');
            } else {
                file_put_contents($workerFilePath, $workerContent);
                $io->success('Created ' . $workerFilePath);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Explicit --profile wins. Without it the TYPO3 context decides, so a
     * Composer auto-run on a production host never writes dev defaults.
     */
    private function resolveProfile(InputInterface $input, SymfonyStyle $io): ?string
    {
        $option = $input->getOption('profile');
        if ($option !== null) {
            $profile = (string)$option;
            if (!in_array($profile, [self::PROFILE_DEV, self::PROFILE_PROD], true)) {
                $io->error(sprintf('Invalid --profile "%s": must be "dev" or "prod".', $profile));
                return null;
            }
            return $profile;
        }
        $profile = Environment::getContext()->isProduction() ? self::PROFILE_PROD : self::PROFILE_DEV;
        $io->note(sprintf('Using profile "%s" (TYPO3_CONTEXT=%s). Pass --profile to override.', $profile, Environment::getContext()));
        return $profile;
    }

    /**
     * Read the worker.php template and substitute the autoload-require line
     * with a path that is correct relative to the target file's location.
     * Mirrors what TYPO3's EntryPoint installer used to do; consolidating it
     * here means a single CLI invocation produces every generated file.
     */
    private function buildWorkerPhp(string $workerFilePath, string $projectPath): ?string
    {
        $templatePath = dirname(__DIR__, 2) . '/Resources/Private/Php/worker.php';
        if (!is_file($templatePath)) {
            return null;
        }
        $content = (string)file_get_contents($templatePath);

        $autoloadPath = $projectPath . '/vendor/autoload.php';
        $requireExpression = $this->computeShortestRequirePath($workerFilePath, $autoloadPath);

        // Replace `require __DIR__ . '<relative path>'` (the template's
        // placeholder) with the freshly-computed shortest-path expression.
        return preg_replace(
            "/require __DIR__ \\. '[^']*'/",
            'require ' . $requireExpression,
            $content,
            1
        );
    }

    /**
     * Build the shortest PHP require expression for one file relative to
     * another. Delegates to Composer's Filesystem when available, otherwise
     * returns the conventional `dirname(__DIR__) . '/vendor/autoload.php'`
     * which is correct for the standard `<project>/public/worker.php` and
     * `<project>/vendor/autoload.php` layout.
     */
    private function computeShortestRequirePath(string $from, string $to): string
    {
        if (class_exists(\Composer\Util\Filesystem::class)) {
            return (new \Composer\Util\Filesystem())->findShortestPathCode($from, $to);
        }
        return "dirname(__DIR__) . '/vendor/autoload.php'";
    }

    private function deriveWebDir(string $projectPath): string
    {
        $publicPath = Environment::getPublicPath();
        if (str_starts_with($publicPath, $projectPath . '/')) {
            return substr($publicPath, strlen($projectPath) + 1);
        }

        return 'public';
    }

    /**
     * @return int<1, 65535>
     */
    private function askPort(mixed $helper, InputInterface $input, OutputInterface $output, string $label, int $default): int
    {
        $question = new Question(
            sprintf('%s [<info>%d</info>]: ', $label, $default),
            (string)$default
        );
        $question->setValidator(static function (mixed $value): int {
            $port = (int)$value;
            if ($port < 1 || $port > 65535) {
                throw new \RuntimeException('Port must be an integer between 1 and 65535.');
            }
            return $port;
        });

        return (int)$helper->ask($input, $output, $question);
    }

    private function buildCaddyfile(string $root, bool $workerMode, bool $isProd, bool $prometheus = false): string
    {
        $entryScript = $workerMode ? '/worker.php' : '/index.php';

        // Prometheus / FrankenPHP metrics. When --prometheus is set, the
        // Caddy admin endpoint exposes Prometheus-format metrics covering
        // Caddy server stats and the FrankenPHP worker pool (workers
        // idle/busy, queue depth, request durations, etc.) on the port
        // chosen during `frankenphp:init` (default 2019), driven by the
        // METRICS_PORT variable in .env.
        //
        // The admin endpoint is localhost-bound AND rejects requests with
        // no Origin header (i.e. direct browser navigations) — this is
        // intentional: the admin API can mutate Caddy's running config,
        // so it has CSRF-style protection baked in. Server-side scrapers
        // (Prometheus, Grafana Agent, curl, k6) don't trip this guard.
        // For browser access or cross-host scraping, add a dedicated
        // metrics-only listener via $CADDY_EXTRA_CONFIG, e.g.
        //   :2020 { metrics }
        //
        // `metrics` (global) exposes admin + Go runtime + FrankenPHP
        // worker pool metrics on the admin endpoint /metrics.
        // NOTE: Caddy v2.11+ removed the legacy per-HTTP-server
        // caddy_http_* families (`servers { metrics }`) in favour of
        // OpenTelemetry-based observability. Only admin, Go, process,
        // and FrankenPHP families are available via Prometheus scrape.
        $metricsGlobal = $prometheus
            ? "\n\t# Prometheus metrics — enables /metrics on the Caddy admin\n\t# endpoint at http://localhost:{\$METRICS_PORT:2019}/metrics.\n\t# Server-side scrapers (Prometheus, curl, k6) reach it directly;\n\t# browsers cannot — Caddy admin rejects requests with no Origin\n\t# header (CSRF guard).\n\tadmin localhost:{\$METRICS_PORT:2019}\n\tmetrics\n"
            : '';

        // The install-tool routing block is identical across profiles —
        // factor it out so a Caddyfile feature added to one profile can't
        // silently miss the other.
        $installToolBlock = <<<CADDY
	# Access rules TYPO3 ships in its .htaccess, which Caddy has no equivalent
	# for: hidden files (except /.well-known/), recycler and temp folders,
	# TypoScript templates, source and config file types, and PHP files inside
	# upload directories (defense in depth behind TYPO3's fileDenyPattern).
	@denied {
		not path /.well-known/*
		path_regexp (?i)(?:^|/)\\.|/_(?:recycler|temp)_/|^/fileadmin/templates/.*\\.(?:txt|ts)$|^/(?:vendor|typo3_src|typo3temp/var)(?:/|$)|\\.(?:bak|co?nf|cfg|neon|ya?ml|ts|typoscript|tsconfig|dist|in[ci]|log|sh|sql(?:\\..*)?|sqlite(?:\\..*)?|sw[op]|git.*|rc)$|^/(?:fileadmin|typo3temp|uploads)/.*\\.(?:php\\d?|phtml|phar)(?:/|$)
	}
	handle @denied {
		respond 403
	}

	# Browser-navigation UX guard: install-tool controllers other than `layout`
	# (maintenance/settings/upgrade/environment/icon) return JsonResponse
	# envelopes meant for the install tool's own JS to inject into a modal.
	# A user pasting such a URL into the address bar would see raw JSON. We
	# detect top-level browser navigations via Sec-Fetch headers and redirect
	# to the install-tool dashboard so the user can click into the right tile.
	# Legitimate AJAX calls (X-Requested-With: XMLHttpRequest) bypass and
	# reach the controller normally.
	@install_browser_ajax {
		query __typo3_install=*
		query install[action]=*
		header Sec-Fetch-Mode navigate
		header Sec-Fetch-Dest document
		not header X-Requested-With XMLHttpRequest
	}
	redir @install_browser_ajax /?__typo3_install 302

	# TYPO3 Install Tool recovery URL (?__typo3_install) bypasses the worker
	# and runs through TYPO3's canonical public/index.php in regular PHP
	# execution. index.php already detects ?__typo3_install and calls
	# Bootstrap::init with \$failsafe=true to expose InstallApplication,
	# so we just route to it instead of shipping our own duplicate.
	# Reach the install tool at: https://…/?__typo3_install
	@typo3_install query __typo3_install=*
	handle @typo3_install {
		rewrite * /index.php
		php
	}

	# Canonical FrankenPHP routing: php_server serves existing static files,
	# routes everything else through the worker entry point.
	# REQUEST_URI is preserved (Caddy sets it from the original client request,
	# not the post-rewrite URI) so TYPO3 can route /typo3/module/* correctly.
	php_server {
		index {$entryScript}
		try_files {path} {$entryScript}
	}
CADDY;

        if ($isProd) {
            $workerBlock = $workerMode
                ? "\n\tfrankenphp {\n\t\tworker {\$FRANKENPHP_WORKER_FILE:{$root}/worker.php} {\$FRANKENPHP_WORKER_COUNT:4}\n\t}\n"
                : '';

            // Caddy enables its admin API on localhost:2019 by default; any
            // local process could reconfigure the server. Off unless metrics
            // need it.
            $adminBlock = $prometheus ? '' : "\n\tadmin off\n";

            // Production: real ports (80/443), ACME-auto-managed TLS via
            // Caddy's site-address shorthand (no scheme prefix = HTTP→HTTPS
            // redirect + ACME provisioning for {$SERVER_NAME}), HSTS &
            // hardening headers, no `debug` directive.
            return <<<CADDYFILE
{
	http_port {\$HTTP_PORT:80}
	https_port {\$HTTPS_PORT:443}
{$adminBlock}{$workerBlock}{$metricsGlobal}
	# https://caddyserver.com/docs/caddyfile/directives#sorting-algorithm
	order mercure after encode
	order vulcain after reverse_proxy
	order php_server before file_server
	order php before file_server
}

{\$CADDY_EXTRA_CONFIG}

# Production site block — Caddy auto-provisions a Let's Encrypt cert for
# {\$SERVER_NAME} (must be a real public DNS name pointing at this host with
# ports 80 + 443 reachable). Set SERVER_NAME in .env to your domain.
{\$SERVER_NAME:localhost} {
	log {
		format filter {
			wrap console
			fields {
				uri query {
					replace authorization REDACTED
					replace token REDACTED
				}
			}
		}
	}

	root * {$root}/

	encode zstd gzip

	# Hardening headers — applied to every response.
	header {
		Strict-Transport-Security "max-age=31536000; includeSubDomains"
		X-Content-Type-Options "nosniff"
		Referrer-Policy "strict-origin-when-cross-origin"
		X-Frame-Options "SAMEORIGIN"
		-Server
	}

	{\$CADDY_SERVER_EXTRA_DIRECTIVES}

{$installToolBlock}
}

CADDYFILE;
        }

        // Development profile (default).
        $workerBlock = $workerMode
            ? "\n\tfrankenphp {\n\t\tworker {\$FRANKENPHP_WORKER_FILE:{$root}/worker.php} {\$FRANKENPHP_WORKER_COUNT:2}\n\t}\n"
            : '';

        return <<<CADDYFILE
{
	# Use non-standard ports to avoid permission issues
	http_port {\$HTTP_PORT:8888}
	https_port {\$HTTPS_PORT:8885}
	auto_https disable_redirects
	debug
{$workerBlock}{$metricsGlobal}
	# https://caddyserver.com/docs/caddyfile/directives#sorting-algorithm
	order mercure after encode
	order vulcain after reverse_proxy
	order php_server before file_server
	order php before file_server
}

{\$CADDY_EXTRA_CONFIG}

# HTTP on 8888, HTTPS on 8885
http://{\$SERVER_NAME:localhost}:{\$HTTP_PORT:8888}, https://{\$SERVER_NAME:localhost}:{\$HTTPS_PORT:8885} {
	log {
		# Redact Mercure's authorization and TYPO3's backend CSRF token
		format filter {
			wrap console
			fields {
				uri query {
					replace authorization REDACTED
					replace token REDACTED
				}
			}
		}
	}

	root * {$root}/

	# Self-signed certificate for localhost HTTPS
	tls internal

	encode zstd gzip

	{\$CADDY_SERVER_EXTRA_DIRECTIVES}

{$installToolBlock}
}

CADDYFILE;
    }

    private function buildEnvFile(
        string $phpIniScanDir,
        string $typo3Context,
        string $serverName,
        int $httpPort,
        int $httpsPort,
        ?string $workerCount,
        ?string $maxRequests,
        ?int $metricsPort = null,
    ): string {
        $workerEnv = '';
        if ($workerCount !== null && $maxRequests !== null) {
            $workerEnv = <<<ENV

# FrankenPHP worker configuration
FRANKENPHP_WORKER_COUNT={$workerCount}
MAX_REQUESTS={$maxRequests}
ENV;
        }

        // Only emitted when --prometheus is set so users on the metrics-off
        // default path don't carry an unused variable in .env. Drives the
        // `admin localhost:{$METRICS_PORT:2019}` directive in the Caddyfile
        // global block (which also controls the /metrics endpoint).
        $metricsEnv = '';
        if ($metricsPort !== null) {
            $metricsEnv = <<<ENV

# Prometheus endpoint port
METRICS_PORT={$metricsPort}
ENV;
        }

        return <<<ENV
PHP_INI_SCAN_DIR={$phpIniScanDir}
TYPO3_CONTEXT={$typo3Context}

SERVER_NAME={$serverName}
HTTP_PORT={$httpPort}
HTTPS_PORT={$httpsPort}
{$workerEnv}{$metricsEnv}

ENV;
    }

    private function fileShouldBeCreated(string $file, SymfonyStyle $io, bool $force = false): bool
    {
        if (file_exists($file) && !$force) {
            $io->warning(sprintf('%s already exist. Pass --force to overwrite.', $file));
            return false;
        }

        return true;
    }

    /**
     * Resolve the absolute path for the generated php.ini inside the
     * PHP_INI_SCAN_DIR the user just picked. Relative values (the default
     * `./`, or e.g. `./conf/`) are anchored to the project root so the
     * file ends up next to Caddyfile / .env when the default is kept.
     */
    private function resolvePhpIniPath(string $projectPath, string $phpIniScanDir): string
    {
        $resolved = trim($phpIniScanDir);
        if ($resolved === '' || $resolved === '.' || $resolved === './') {
            $resolved = $projectPath;
        } elseif (!str_starts_with($resolved, '/')) {
            $resolved = $projectPath . '/' . ltrim($resolved, './');
        }

        return rtrim($resolved, '/') . '/php.ini';
    }

    /**
     * Sane TYPO3 PHP defaults. Two profiles:
     *
     *   dev  (--profile=dev OR TYPO3_CONTEXT=Development…) — display_errors=On,
     *        zend.assertions=1, opcache.validate_timestamps=1 (re-check every
     *        request), modest opcache sizing, no JIT.
     *
     *   prod (--profile=prod AND TYPO3_CONTEXT not Development) — errors off,
     *        assertions off, opcache.validate_timestamps=0 (flush opcache on
     *        deploy), bigger opcache sizing, JIT off (PHP default), realpath
     *        cache tuned, expose_php=Off.
     *
     * Note: max_execution_time is largely a no-op in FrankenPHP worker mode.
     */
    private function buildPhpIni(string $typo3Context, bool $isProd): string
    {
        // The --profile flag is the primary signal, but a hand-edited
        // TYPO3_CONTEXT=Development on a prod-profile setup should still
        // surface errors — and the inverse (Production context on a
        // dev-profile setup) should hide them. Treat dev as the union:
        // either the flag picked dev OR the context says development.
        $isDevContext = str_starts_with(strtolower($typo3Context), 'development');
        $isDev = !$isProd || $isDevContext;

        if ($isDev) {
            $displayErrors = 'On';
            $errorReporting = 'E_ALL';
            $zendAssertions = '1';
            $assertException = '1';
            return <<<INI
; TYPO3-recommended PHP defaults (development profile).
; Generated by `vendor/bin/typo3 frankenphp:init`.
; Existing files are preserved on re-run — pass --force to regenerate.

; Limits
memory_limit = 512M
max_execution_time = 240
max_input_vars = 1500
post_max_size = 32M
upload_max_filesize = 32M

; Sessions
session.cookie_secure = On
session.cookie_httponly = On
session.cookie_samesite = "Lax"
session.gc_maxlifetime = 7200

; Timezone
date.timezone = "UTC"

; Errors (TYPO3_CONTEXT={$typo3Context})
display_errors = {$displayErrors}
display_startup_errors = {$displayErrors}
log_errors = On
error_reporting = {$errorReporting}

; Assertions
zend.assertions = {$zendAssertions}
assert.exception = {$assertException}

; OPcache (dev: validate timestamps so edits are picked up on next request)
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 16229
opcache.validate_timestamps = 1

INI;
        }

        // Production profile.
        return <<<INI
; TYPO3-recommended PHP defaults (production profile).
; Generated by `vendor/bin/typo3 frankenphp:init`.
; Existing files are preserved on re-run — pass --force to regenerate.

; Limits
memory_limit = 512M
max_execution_time = 240
max_input_vars = 1500
post_max_size = 32M
upload_max_filesize = 32M

; Sessions
session.cookie_secure = On
session.cookie_httponly = On
session.cookie_samesite = "Lax"
session.gc_maxlifetime = 7200

; Timezone
date.timezone = "UTC"

; Don't leak the PHP version in HTTP responses.
expose_php = Off

; Errors (TYPO3_CONTEXT={$typo3Context}) — never display, always log.
display_errors = Off
display_startup_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED

; Assertions disabled (zend.assertions=-1 strips them at compile time).
zend.assertions = -1
assert.exception = 0

; OPcache — production tuning. validate_timestamps=0 means TYPO3 will NOT
; pick up file edits without an explicit opcache_reset() / FPM restart /
; FrankenPHP reload, so deploy pipelines must flush the cache.
opcache.enable = 1
opcache.memory_consumption = 512
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0

; OPcache JIT stays at PHP's default (off). The tracing JIT crashed
; FrankenPHP workers under load on PHP 8.4/8.5 (php-src #22084, #22443).
; To try it: opcache.jit = function, opcache.jit_buffer_size = 64M, then
; load-test before rolling out.

; Realpath cache — bigger + longer-lived than PHP defaults.
realpath_cache_size = 4096k
realpath_cache_ttl = 600

INI;
    }
}
