<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Controller;

use Ochorocho\FrankenPhp\Controller\Backend\MetricsAjaxController;
use Ochorocho\FrankenPhp\Service\PrometheusTextParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;

final class MetricsAjaxControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function rejectsUsersWithoutTheWidgetPermission(): void
    {
        $this->loginWithWidgetPermission(false);
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::never())->method('request');

        $response = (new MetricsAjaxController($requestFactory, new PrometheusTextParser()))
            ->indexAction(new ServerRequest());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function rejectsRequestsWithoutABackendUser(): void
    {
        $response = (new MetricsAjaxController(self::createStub(RequestFactory::class), new PrometheusTextParser()))
            ->indexAction(new ServerRequest());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function returnsParsedMetricsWithoutInternalUrl(): void
    {
        $this->loginWithWidgetPermission(true);
        $upstream = new Response();
        $upstream->getBody()->write("# TYPE frankenphp_busy_threads gauge\nfrankenphp_busy_threads 2\n");
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturn($upstream);

        $response = (new MetricsAjaxController($requestFactory, new PrometheusTextParser()))
            ->indexAction(new ServerRequest());

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertEquals(2, $payload['metrics']['frankenphp_busy_threads']['samples'][0]['value']);
        self::assertArrayNotHasKey('url', $payload);
    }

    private function loginWithWidgetPermission(bool $granted): void
    {
        $user = self::createStub(BackendUserAuthentication::class);
        $user->method('check')->willReturn($granted);
        $GLOBALS['BE_USER'] = $user;
    }
}
