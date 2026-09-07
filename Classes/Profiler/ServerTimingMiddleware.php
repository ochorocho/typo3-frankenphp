<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Outermost middleware of the `core` stack: adds the Server-Timing header
 * with the profile collected so far. It runs before sendResponse(), the last
 * moment a header can still be added under FrankenPHP.
 */
final readonly class ServerTimingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestProfile $profile,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!$this->profile->isEnabled()) {
            return $response;
        }
        $this->profile->setResponseStatus($response->getStatusCode());
        return $response->withAddedHeader('Server-Timing', $this->profile->serverTimingHeader());
    }
}
