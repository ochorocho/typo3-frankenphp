<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp;

use Ochorocho\FrankenPhp\DependencyInjection\WorkerKeepListPass;
use Ochorocho\FrankenPhp\Profiler\Database\ProfilingDriverMiddleware;
use Ochorocho\FrankenPhp\Profiler\ProfileEventProbes;
use Ochorocho\FrankenPhp\Profiler\ProfilingMiddlewareStackResolver;
use Ochorocho\FrankenPhp\Profiler\ProfilingTimeTracker;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use Ochorocho\FrankenPhp\Profiler\ServerTimingMiddleware;
use Ochorocho\FrankenPhp\Widget\PrometheusMetricsWidget;
use Ochorocho\FrankenPhp\Worker\WorkerConfigurationFileLocator;
use Ochorocho\FrankenPhp\Worker\WorkerConfigurationLoader;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Dashboard\WidgetRegistry;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    // Classify services for the worker lifecycle. Must run after Symfony's
    // removing passes (inlining done, reference graph final) and before
    // RemoveBuildParametersPass at -2048. Packages tune the result through
    // Configuration/FrankenPhpWorker.php, the project through
    // config/system/frankenphp-worker.php.
    $locator = new WorkerConfigurationFileLocator(
        logger: GeneralUtility::makeInstance(LogManager::class)->getLogger(WorkerConfigurationFileLocator::class)
    );
    $configuration = (new WorkerConfigurationLoader())->load($locator->locate());
    $containerBuilder->addCompilerPass(
        new WorkerKeepListPass(configuration: $configuration),
        PassConfig::TYPE_AFTER_REMOVING,
        -1024
    );

    // Request profiler (Documentation/Profiling.md): compiled into Development
    // containers only, recording only while worker.php saw FRANKENPHP_PROFILE=1.
    // The collector is pinned so it survives the per-request discard; the
    // resolver decorator wraps every middleware in a TimedMiddleware; the
    // TimeTracker class is swapped for one that records Core's own push/pull
    // spans; the driver middleware is public because ConnectionPool
    // instantiates it with makeInstance().
    if (Environment::getContext()->isDevelopment()) {
        $services = $container->services()->defaults()->autowire()->autoconfigure()->private();
        $services->set(RequestProfile::class)->public()->tag(WorkerKeepListPass::TAG_KEEP, ['mode' => 'pinned']);
        $services->set(ProfilingMiddlewareStackResolver::class)->decorate(MiddlewareStackResolver::class);
        $services->set(TimeTracker::class, ProfilingTimeTracker::class)->public();
        $services->set(ServerTimingMiddleware::class);
        $services->set(ProfileEventProbes::class);
        $services->set(ProfilingDriverMiddleware::class)->public();
    }

    // Dashboard widget registration is conditional on cms-dashboard being
    // installed — that package is listed as `suggest` in composer.json,
    // not `require`. The WidgetRegistry definition only exists when the
    // dashboard system extension is loaded, so guarding here keeps the
    // extension functional in installs that don't ship the dashboard.
    if (!$containerBuilder->hasDefinition(WidgetRegistry::class)) {
        return;
    }

    // Autowire so BackendViewFactory + UriBuilder are resolved from the
    // container — the YAML `resource: '../Classes/*'` autodiscovery would
    // pick the class up too, but we still need an explicitly-named entry
    // here so the `dashboard.widget` tag can be attached.
    $container->services()
        ->set('frankenphp.widget.prometheusMetrics', PrometheusMetricsWidget::class)
        ->autowire()
        ->tag('dashboard.widget', [
            'identifier'     => 'frankenphp-prometheus-metrics',
            'groupNames'     => 'frankenphp',
            'title'          => 'LLL:EXT:frankenphp/Resources/Private/Language/locallang_dashboard.xlf:widget.prometheusMetrics.title',
            'description'    => 'LLL:EXT:frankenphp/Resources/Private/Language/locallang_dashboard.xlf:widget.prometheusMetrics.description',
            'iconIdentifier' => 'content-widget-chart-bar',
            'height'         => 'medium',
            'width'          => 'medium',
        ]);
};
