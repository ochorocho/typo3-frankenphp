<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp;

use Ochorocho\FrankenPhp\DependencyInjection\WorkerKeepListPass;
use Ochorocho\FrankenPhp\Widget\PrometheusMetricsWidget;
use Ochorocho\FrankenPhp\Worker\WorkerConfigurationFileLocator;
use Ochorocho\FrankenPhp\Worker\WorkerConfigurationLoader;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Log\LogManager;
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
