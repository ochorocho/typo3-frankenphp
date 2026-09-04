# TYPO3 FrankenPHP integration

Provides one CLI command that generates everything needed to run TYPO3 under FrankenPHP:

```
vendor/bin/typo3 frankenphp:init
# or without prompts:
vendor/bin/typo3 frankenphp:init --no-interaction
# overwrite existing files:
vendor/bin/typo3 frankenphp:init --no-interaction --force
# production defaults (ports 80/443, TYPO3_CONTEXT=Production, larger worker pool):
vendor/bin/typo3 frankenphp:init --profile prod
# expose Caddy + FrankenPHP Prometheus metrics on localhost:METRICS_PORT (default 2019):
vendor/bin/typo3 frankenphp:init --prometheus
```

`--profile dev|prod` drives the defaults for ports, `TYPO3_CONTEXT`, worker count, and `max_requests`. `--prometheus`
adds the `metrics` directive and an `admin localhost:METRICS_PORT` block to the Caddyfile and powers the live dashboard
widget described below.

Composer also runs the command automatically on package install / update / `dump-autoload` via the TYPO3
installer-scripts mechanism, so users who don't touch the CLI still get a working setup. Without `--force`, the command
preserves files that already exist (warns instead of overwriting), so a `composer update` won't clobber a hand-edited
`Caddyfile`, `.env`, `php.ini`, or `public/worker.php`.

**The files:**

* Worker entrypoint: `public/worker.php` — long-running FrankenPHP worker for the full backend + frontend (
  `HttpApplication`).
* Webserver config: `Caddyfile` — routes `?__typo3_install` queries to canonical `public/index.php` (which TYPO3 ships
  and which handles `Bootstrap::init($failsafe=true)` internally), and everything else through the worker.
* Environment config: `.env`
* PHP runtime config: `php.ini` — profile-aware (e.g. `display_errors`, `opcache.validate_timestamps`).

The install-tool recovery URL needs `Bootstrap::init` with `$failsafe=true` so the container exposes
`InstallApplication` — mutually exclusive with the worker's always-on `HttpApplication` boot. Rather than shipping a
duplicate entry-point, the Caddyfile routes those requests to TYPO3's existing canonical `public/index.php`, which
already implements that branch. `index.php` is not registered as a FrankenPHP worker, so requests reach it as standard
per-request PHP execution.

## For users — install into an existing TYPO3

```
composer require ochorocho/frankenphp
```

Then run FrankenPHP from the project root using the created config files (`Caddyfile`, `.env`):

```
frankenphp run -c Caddyfile -e .env
```

## Diagnostics

The TYPO3 backend's **System Information** dropdown (the info icon in the topbar) shows a **Worker Mode** row —
`Enabled` when the current request is being served by the long-running FrankenPHP worker, `Disabled` when served by
per-request PHP execution (e.g. the install-tool recovery URL via `/index.php`). The row icon is the FrankenPHP mascot (
the skeleton elephant from `frankenphp.dev`). Use it to quickly verify that requests you expect to hit the worker
actually do.

## Prometheus metrics dashboard widget

Run `vendor/bin/typo3 frankenphp:init --prometheus` (add `--force` to overwrite an existing Caddyfile / `.env`). This
adds:

- `metrics` + `admin localhost:METRICS_PORT` to the Caddyfile global block.
- `METRICS_PORT=` (default `2019`) to `.env`.

A dashboard widget titled **Prometheus Metrics** then appears in the *FrankenPHP* widget group. It charts the metric you
pick — FrankenPHP worker-pool gauges, Caddy HTTP counters/histograms, or Go runtime stats — by polling the backend AJAX
route `ajax_frankenphp_metrics` (`Configuration/Backend/AjaxRoutes.php`), which proxies
`http://127.0.0.1:METRICS_PORT/metrics`. The proxy exists because Caddy's admin endpoint rejects any browser request
that ships an `Origin` header; only server-side scrapers (this proxy, Prometheus, `curl`) can reach it directly.

The curated metric list lives in `PrometheusMetricsWidget::METRIC_CHOICES`. Enumerate what your build actually exposes
with:

```
curl http://localhost:2019/metrics | grep "^# TYPE"
```

## Install Tool access

Two URLs reach the TYPO3 Install Tool, each routed differently:

* **`https://your-host/typo3/install`** — preferred for normal maintenance. Goes through the worker via the standard
  backend route. Requires a logged-in admin backend session.
* **`https://your-host/?__typo3_install`** — recovery URL. Caddy routes this to TYPO3's canonical `public/index.php` (
  which boots with `$failsafe=true` and runs `InstallApplication`). Works without backend login but requires the unlock
  file `public/typo3conf/ENABLE_INSTALL_TOOL` (create via `touch public/typo3conf/ENABLE_INSTALL_TOOL`; auto-removed
  after one hour). Also accepts the standard controller-routing query parameters, e.g.
  `?__typo3_install&install[controller]=maintenance`.

If you ever change the Caddyfile manually and forget to keep the `@typo3_install` matcher, the recovery URL will 404 —
`vendor/bin/typo3 frankenphp:init --force --no-interaction` regenerates a working config.

### Action URLs are AJAX-only

URLs that carry both `install[controller]=…` and `install[action]=…` (anything other than `install[controller]=layout`)
are designed for the install tool's own JS to call via `XMLHttpRequest`. They return a JSON envelope
`{success: true, html: '…', buttons: [...]}` for the JS to inject into a modal. Pasting such a URL into a browser
address bar shows the raw JSON, not a usable page.

To avoid that confusion, the Caddyfile's `@install_browser_ajax` matcher detects browser top-level navigation (
`Sec-Fetch-Mode: navigate` + `Sec-Fetch-Dest: document`, without `X-Requested-With: XMLHttpRequest`) to
`?__typo3_install&install[action]=…` URLs and redirects (302) to the install tool dashboard at `/?__typo3_install`. From
there, click into the relevant tile (Maintenance, Settings, Upgrade, Environment). The redirect is a Caddyfile-level
concern; no extra PHP entry-point is involved.

For long-running maintenance like the reference index, the CLI alternative is usually preferable — the install tool's
own UI literally points at this:

```bash
vendor/bin/typo3 referenceindex:update -c   # check only
vendor/bin/typo3 referenceindex:update      # rebuild
```

## Repository layout

This repository is the **extension package**, not a TYPO3 installation. A throwaway TYPO3 sandbox is materialized in
`Build/` (gitignored) so the extension can be exercised end-to-end.

| Folder               | Purpose                                                                                                                                                                                                                                                                                                                                                     |
|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `Classes/`           | Extension PHP source — `Command/` (`frankenphp:init`), `Controller/Backend/` (metrics AJAX proxy), `Service/` (`PrometheusTextParser`), `Widget/` (`PrometheusMetricsWidget`), `Worker/` (`StateSnapshotService` — survives singleton state across worker requests), `EventListener/`, `Middleware/`, `Event/`, `Composer/` (TYPO3 installer-scripts hook). |
| `Configuration/`     | TYPO3 service wiring (`Services.yaml`, `Services.php`), `Backend/AjaxRoutes.php`, `Backend/DashboardWidgetGroups.php`, `Backend/DashboardPresets.php`, `JavaScriptModules.php`, `Icons.php`, `RequestMiddlewares.php`.                                                                                                                                      |
| `Resources/Private/` | Fluid templates (`Templates/Widget/`), `Language/` XLF files, and `Php/worker.php` — the template `InitCommand` copies into the user's `public/`.                                                                                                                                                                                                           |
| `Resources/Public/`  | Frontend assets — `JavaScript/widget/` (Chart.js-backed Lit web component for the metrics widget), `Css/widget/`, `Icons/`.                                                                                                                                                                                                                                 |
| `Tests/`             | `e2e/` Playwright suite (correctness) and `load/` k6 scenarios (performance + worker stability). Each has its own README.                                                                                                                                                                                                                                   |
| `scripts/`           | Developer bootstrap — `setup-typo3.sh` materializes the `Build/` sandbox.                                                                                                                                                                                                                                                                                   |
| `Build/`             | **Gitignored.** Throwaway TYPO3 install for development. `Build/composer.json` requires this extension via a Composer path repository pointing at `../`, so edits to root `Classes/` / `Resources/` / `Configuration/` affect the sandbox immediately.                                                                                                      |

## Contributing

### Prerequisites

- PHP 8.3+, Composer, `sqlite3` on `$PATH`.
- `frankenphp` binary on `$PATH` — see https://frankenphp.dev/docs/install/.
- ImageMagick is optional and auto-detected by `setup-typo3.sh`. Override with `MAGICK_BIN=/abs/path/to/magick`.

### Bootstrap the dev sandbox

```bash
git clone git@github.com:ochorocho/typo3-frankenphp.git
cd typo3-frankenphp
scripts/setup-typo3.sh                              # TYPO3 ^14.3 (default)
TYPO3_VERSION='^13.0' scripts/setup-typo3.sh        # or any Composer constraint
TYPO3_VERSION='15.*@dev' scripts/setup-typo3.sh
```

The script is **idempotent**. On a re-run with the same `TYPO3_VERSION` it skips work that's already done; with a
different version it resets `Build/vendor/`, `composer.lock`, `config/system/`, and `var/cache` before re-installing —
so switching TYPO3 majors is one command. It writes a `Build/composer.json` that requires the extension as a symlinked
path repository (`"url": "../"`), so editing root files affects the sandbox immediately with no extra step.

Admin login (created by `typo3 setup`): `admin` / `Password.1`. Override via:

```bash
TYPO3_SETUP_ADMIN_USERNAME=foo TYPO3_SETUP_ADMIN_PASSWORD='S3cret!' scripts/setup-typo3.sh
```

### Run the dev server

```bash
cd Build && frankenphp run
```

- Frontend: http://localhost:8888 / https://localhost:8885
- Backend:  https://localhost:8885/typo3

To regenerate `Caddyfile` / `.env` / `php.ini` / `public/worker.php` after switching profiles or toggling
`--prometheus`:

```bash
cd Build && vendor/bin/typo3 frankenphp:init --no-interaction --force
```

### Static analysis & code style

Dev dependencies are pinned in `Build/composer.json`, not the root package — run the tools from inside `Build/`:

```bash
cd Build && vendor/bin/phpstan analyse ../Classes
cd Build && vendor/bin/php-cs-fixer fix ../Classes
```

### Tests

| Suite                   | Location      | What it covers                                                                                                                                 |
|-------------------------|---------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| End-to-end (Playwright) | `Tests/e2e/`  | Backend correctness against the running sandbox. See `Tests/README.md`.                                                                        |
| Load / soak (k6)        | `Tests/load/` | Throughput, tail latency, and (most importantly) the regression net for `Classes/Worker/StateSnapshotService.php`. See `Tests/load/README.md`. |

### Submitting changes

Standard GitHub PR workflow against `main`. Please make sure `phpstan` and `php-cs-fixer` are clean and include a
Playwright or k6 test when the change is behavior-visible.
