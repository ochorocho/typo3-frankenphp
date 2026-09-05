<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Controller\Backend;

use Ochorocho\FrankenPhp\Service\PrometheusTextParser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Proxies Caddy's admin /metrics endpoint for the dashboard widget. Caddy
 * admin rejects browser requests (Origin header), so PHP scrapes it.
 */
final class MetricsAjaxController
{
    public const string WIDGET_IDENTIFIER = 'frankenphp-prometheus-metrics';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly PrometheusTextParser $parser,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        // Same gate the dashboard applies before showing the widget.
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !$user->check('available_widgets', self::WIDGET_IDENTIFIER)) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        // FrankenPHP runs locally, only the port varies.
        $url = sprintf('http://127.0.0.1:%d/metrics', $this->resolveMetricsPort());

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout'         => 5.0,
                'connect_timeout' => 2.0,
                'http_errors'     => false,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error'  => 'metrics endpoint unreachable',
                'detail' => $e->getMessage(),
                'hint'   => 'Run `vendor/bin/typo3 frankenphp:init --prometheus` and restart frankenphp.',
            ], 503);
        }

        if ($response->getStatusCode() !== 200) {
            return new JsonResponse(['error' => sprintf('metrics endpoint returned HTTP %d', $response->getStatusCode())], 502);
        }

        return new JsonResponse(['metrics' => $this->parser->parse((string)$response->getBody())]);
    }

    private function resolveMetricsPort(): int
    {
        $env = getenv('METRICS_PORT');
        if ($env !== false && ctype_digit((string)$env)) {
            $port = (int)$env;
            if ($port > 0 && $port <= 65535) {
                return $port;
            }
        }
        return 2019;
    }
}
