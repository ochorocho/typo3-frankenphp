<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Profiler;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stands in for one middleware of a resolved stack and times its process().
 *
 * The resolved `*.middlewares` ArrayObjects survive worker requests, so this
 * object lives for the worker's lifetime and must not hold request state: the
 * real middleware is resolved from the container on every call, exactly like
 * Core's lazy handler in MiddlewareDispatcher::lazy() does.
 */
final readonly class TimedMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestProfile $profile,
        private ContainerInterface $container,
        private string $identifier,
        private string $target,
    ) {}

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->profile->isEnabled()) {
            return $this->resolve()->process($request, $handler);
        }
        $this->profile->enter(RequestProfile::KIND_MIDDLEWARE, $this->identifier);
        try {
            return $this->resolve()->process($request, $handler);
        } finally {
            $this->profile->leave(RequestProfile::KIND_MIDDLEWARE);
        }
    }

    private function resolve(): MiddlewareInterface
    {
        /** @var class-string $target */
        $target = $this->target;
        $middleware = $this->container->has($target)
            ? $this->container->get($target)
            : GeneralUtility::makeInstance($target);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new \InvalidArgumentException(
                sprintf('Middleware "%s" (%s) does not implement %s.', $this->identifier, $this->target, MiddlewareInterface::class),
                1757200001
            );
        }
        return $middleware;
    }
}
