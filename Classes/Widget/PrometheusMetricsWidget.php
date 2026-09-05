<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Widget;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Dashboard\Widgets\AdditionalCssInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Live Prometheus metrics widget. Renders a Chart.js chart whose data
 * is polled every few seconds from the AJAX endpoint that proxies
 * Caddy's /metrics. Chart type is derived from the metric's Prometheus
 * TYPE (counter/gauge → line, summary → multi-line per quantile,
 * histogram → bar of bucket distribution).
 *
 * Settings (rendered as a native TYPO3 dashboard widget settings dialog
 * via getSettingsDefinitions()):
 *   - metric: which Prometheus metric family to chart
 *   - label:  optional title override; falls back to the metric name
 */
final readonly class PrometheusMetricsWidget implements
    WidgetRendererInterface,
    JavaScriptInterface,
    AdditionalCssInterface
{
    /**
     * Curated picker enum — covers FrankenPHP, the most useful Caddy
     * HTTP metrics, and a handful of Go runtime gauges. Add entries
     * here (or override the SettingDefinition.enum at registration
     * time) to surface additional metrics in the dropdown.
     */
    // Keep this list in sync with what FrankenPHP / Caddy / Go runtime
    // ACTUALLY emit on the admin /metrics endpoint — adding a metric
    // here that the endpoint doesn't expose will trigger the widget's
    // "metric not exposed" error at runtime. To enumerate authoritative
    // names: `curl http://localhost:2019/metrics | grep "^# TYPE"`.
    private const array METRIC_CHOICES = [
        'caddy_admin_http_requests_total' => "Counter of requests made to the Admin API's HTTP endpoints.",
        'caddy_config_last_reload_success_timestamp_seconds' => 'Timestamp of the last successful configuration reload.',
        'caddy_config_last_reload_successful' => 'Whether the last configuration reload attempt was successful.',
        'go_build_info' => 'Build information about the main Go module.',
        'go_gc_duration_seconds' => 'A summary of the wall-time pause (stop-the-world) duration in garbage collection cycles.',
        'go_gc_gogc_percent' => 'Heap size target percentage configured by the user, otherwise 100. This value is set by the GOGC environment variable, and the runtime/debug.SetGCPercent function. Sourced from /gc/gogc:percent.',
        'go_gc_gomemlimit_bytes' => 'Go runtime memory limit configured by the user, otherwise math.MaxInt64. This value is set by the GOMEMLIMIT environment variable, and the runtime/debug.SetMemoryLimit function. Sourced from /gc/gomemlimit:bytes.',
        'go_goroutines' => 'Number of goroutines that currently exist.',
        'go_info' => 'Information about the Go environment.',
        'go_memstats_alloc_bytes' => 'Number of bytes allocated in heap and currently in use. Equals to /memory/classes/heap/objects:bytes.',
        'go_memstats_alloc_bytes_total' => 'Total number of bytes allocated in heap until now, even if released already. Equals to /gc/heap/allocs:bytes.',
        'go_memstats_buck_hash_sys_bytes' => 'Number of bytes used by the profiling bucket hash table. Equals to /memory/classes/profiling/buckets:bytes.',
        'go_memstats_frees_total' => 'Total number of heap objects frees. Equals to /gc/heap/frees:objects + /gc/heap/tiny/allocs:objects.',
        'go_memstats_gc_sys_bytes' => 'Number of bytes used for garbage collection system metadata. Equals to /memory/classes/metadata/other:bytes.',
        'go_memstats_heap_alloc_bytes' => 'Number of heap bytes allocated and currently in use, same as go_memstats_alloc_bytes. Equals to /memory/classes/heap/objects:bytes.',
        'go_memstats_heap_idle_bytes' => 'Number of heap bytes waiting to be used. Equals to /memory/classes/heap/released:bytes + /memory/classes/heap/free:bytes.',
        'go_memstats_heap_inuse_bytes' => 'Number of heap bytes that are in use. Equals to /memory/classes/heap/objects:bytes + /memory/classes/heap/unused:bytes.',
        'go_memstats_heap_objects' => 'Number of currently allocated objects. Equals to /gc/heap/objects:objects.',
        'go_memstats_heap_released_bytes' => 'Number of heap bytes released to OS. Equals to /memory/classes/heap/released:bytes.',
        'go_memstats_heap_sys_bytes' => 'Number of heap bytes obtained from system. Equals to /memory/classes/heap/objects:bytes + /memory/classes/heap/unused:bytes + /memory/classes/heap/released:bytes + /memory/classes/heap/free:bytes.',
        'go_memstats_last_gc_time_seconds' => 'Number of seconds since 1970 of last garbage collection.',
        'go_memstats_mallocs_total' => 'Total number of heap objects allocated, both live and gc-ed. Semantically a counter version for go_memstats_heap_objects gauge. Equals to /gc/heap/allocs:objects + /gc/heap/tiny/allocs:objects.',
        'go_memstats_mcache_inuse_bytes' => 'Number of bytes in use by mcache structures. Equals to /memory/classes/metadata/mcache/inuse:bytes.',
        'go_memstats_mcache_sys_bytes' => 'Number of bytes used for mcache structures obtained from system. Equals to /memory/classes/metadata/mcache/inuse:bytes + /memory/classes/metadata/mcache/free:bytes.',
        'go_memstats_mspan_inuse_bytes' => 'Number of bytes in use by mspan structures. Equals to /memory/classes/metadata/mspan/inuse:bytes.',
        'go_memstats_mspan_sys_bytes' => 'Number of bytes used for mspan structures obtained from system. Equals to /memory/classes/metadata/mspan/inuse:bytes + /memory/classes/metadata/mspan/free:bytes.',
        'go_memstats_next_gc_bytes' => 'Number of heap bytes when next garbage collection will take place. Equals to /gc/heap/goal:bytes.',
        'go_memstats_other_sys_bytes' => 'Number of bytes used for other system allocations. Equals to /memory/classes/other:bytes.',
        'go_memstats_stack_inuse_bytes' => 'Number of bytes obtained from system for stack allocator in non-CGO environments. Equals to /memory/classes/heap/stacks:bytes.',
        'go_memstats_stack_sys_bytes' => 'Number of bytes obtained from system for stack allocator. Equals to /memory/classes/heap/stacks:bytes + /memory/classes/os-stacks:bytes.',
        'go_memstats_sys_bytes' => 'Number of bytes obtained from system. Equals to /memory/classes/total:byte.',
        'go_sched_gomaxprocs_threads' => 'The current runtime.GOMAXPROCS setting, or the number of operating system threads that can execute user-level Go code simultaneously. Sourced from /sched/gomaxprocs:threads.',
        'go_threads' => 'Number of OS threads created.',
        'process_cpu_seconds_total' => 'Total user and system CPU time spent in seconds.',
        'process_max_fds' => 'Maximum number of open file descriptors.',
        'process_open_fds' => 'Number of open file descriptors.',
        'process_resident_memory_bytes' => 'Resident memory size in bytes.',
        'process_start_time_seconds' => 'Start time of the process since unix epoch in seconds.',
        'process_virtual_memory_bytes' => 'Virtual memory size in bytes.',
        'process_virtual_memory_max_bytes' => 'Maximum amount of virtual memory available in bytes.',
        'promhttp_metric_handler_requests_in_flight' => 'Current number of scrapes being served.',
        'promhttp_metric_handler_requests_total' => 'Total number of scrapes by HTTP status code.',
    ];

    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private BackendViewFactory           $backendViewFactory,
        private UriBuilder                   $uriBuilder,
    ) {}

    public function getSettingsDefinitions(): array
    {
        return [
            new SettingDefinition(
                key: 'metric',
                type: 'string',
                default: 'frankenphp_busy_threads',
                label: 'LLL:EXT:frankenphp/Resources/Private/Language/locallang_dashboard.xlf:widget.prometheusMetrics.setting.metric.label',
                description: 'LLL:EXT:frankenphp/Resources/Private/Language/locallang_dashboard.xlf:widget.prometheusMetrics.setting.metric.description',
                enum: self::METRIC_CHOICES,
            ),
            new SettingDefinition(
                key: 'label',
                type: 'string',
                default: '',
                label: 'LLL:EXT:frankenphp/Resources/Private/Language/locallang_dashboard.xlf:widget.prometheusMetrics.setting.label.label',
                description: 'LLL:EXT:frankenphp/Resources/Private/Language/locallang_dashboard.xlf:widget.prometheusMetrics.setting.label.description',
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $metric = (string)$context->settings->get('metric');
        $label = (string)$context->settings->get('label');

        $view = $this->backendViewFactory->create($context->request, ['ochorocho/frankenphp']);
        $view->assignMultiple([
            'instance' => $context->identifier,
            'metric' => $metric,
            'label' => $label !== '' ? $label : $metric,
            // TYPO3 prefixes AjaxRoutes.php entries with `ajax_` in its
            // internal route registry, so the lookup name is not the
            // bare key from AjaxRoutes.php — it's `ajax_<key>`.
            'ajaxUrl' => (string)$this->uriBuilder->buildUriFromRoute('ajax_frankenphp_metrics'),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult(
            content: $view->render('Widget/PrometheusMetrics'),
            label: $label !== '' ? $label : null,
            refreshable: false,
        );
    }

    public function getJavaScriptModuleInstructions(): array
    {
        // The web component self-registers under <frankenphp-prometheus-widget>
        // on module load; no invoke() needed — the Fluid template ships
        // the custom element instance with all data-* attributes the
        // component reads on connectedCallback.
        return [
            JavaScriptModuleInstruction::create('@ochorocho/frankenphp/widget/prometheus-metrics.js'),
        ];
    }

    public function getCssFiles(): array
    {
        return ['EXT:frankenphp/Resources/Public/Css/widget/prometheus-metrics.css'];
    }
}
