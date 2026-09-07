<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Profiler;

use Ochorocho\FrankenPhp\Profiler\ProfilingMiddlewareStackResolver;
use Ochorocho\FrankenPhp\Profiler\RequestProfile;
use Ochorocho\FrankenPhp\Profiler\TimedMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;

final class TimedMiddlewareTest extends TestCase
{
    private RequestProfile $profile;
    private Container $container;

    protected function setUp(): void
    {
        $this->profile = new RequestProfile();
        $this->profile->enable(true);
        $this->profile->beginRequest(hrtime(true));
        $this->container = new Container();
    }

    private function passThrough(): MiddlewareInterface
    {
        return new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
    }

    private function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }

    #[Test]
    public function recordsTheIdentifierAsSpanAndResolvesOnEveryCall(): void
    {
        $this->container->set('mw.a', $this->passThrough());
        $timed = new TimedMiddleware($this->profile, $this->container, 'typo3/cms-frontend/site', 'mw.a');

        $timed->process(new ServerRequest(), $this->handler());
        // A plain Container returns the same instance; replace it to prove the wrapper asks again.
        $this->container->set('mw.a', $this->passThrough());
        $timed->process(new ServerRequest(), $this->handler());

        $frames = array_values($this->profile->frames());
        self::assertSame('typo3/cms-frontend/site', $frames[0]['name']);
        self::assertSame(2, $frames[0]['calls']);
    }

    #[Test]
    public function leavesTheFrameWhenTheMiddlewareThrows(): void
    {
        $this->container->set('mw.boom', new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        });
        $timed = new TimedMiddleware($this->profile, $this->container, 'boom', 'mw.boom');

        try {
            $timed->process(new ServerRequest(), $this->handler());
            self::fail('expected the exception to propagate');
        } catch (\RuntimeException) {
        }

        self::assertCount(1, $this->profile->frames());
        $this->profile->enter(RequestProfile::KIND_MIDDLEWARE, 'next');
        $this->profile->leave(RequestProfile::KIND_MIDDLEWARE);
        self::assertSame(['boom', 'next'], array_column(array_values($this->profile->frames()), 'name'), 'the stack was left balanced');
    }

    #[Test]
    public function rejectsTargetsThatAreNoMiddleware(): void
    {
        $this->container->set('not.a.middleware', new \stdClass());
        $timed = new TimedMiddleware($this->profile, $this->container, 'x', 'not.a.middleware');

        $this->expectException(\InvalidArgumentException::class);
        $timed->process(new ServerRequest(), $this->handler());
    }

    #[Test]
    public function disabledProfileStillRunsTheMiddlewareWithoutRecording(): void
    {
        $this->profile->enable(false);
        $this->container->set('mw.a', $this->passThrough());
        $timed = new TimedMiddleware($this->profile, $this->container, 'a', 'mw.a');

        self::assertSame(200, $timed->process(new ServerRequest(), $this->handler())->getStatusCode());
        self::assertSame([], $this->profile->frames());
    }

    #[Test]
    public function resolverDecoratorWrapsEveryEntryKeepingKeysAndOrder(): void
    {
        $inner = $this->createMock(MiddlewareStackResolver::class);
        $inner->method('resolve')->with('frontend')->willReturn(new \ArrayObject(['b' => 'Class\B', 'a' => 'Class\A']));
        $resolver = new ProfilingMiddlewareStackResolver($inner, $this->profile, $this->container);

        $stack = $resolver->resolve('frontend');

        self::assertSame(['b', 'a'], array_keys($stack->getArrayCopy()));
        self::assertContainsOnlyInstancesOf(TimedMiddleware::class, $stack->getArrayCopy());
        self::assertSame('Class\B', $stack['b']->target());
        self::assertSame('b', $stack['b']->identifier());
    }
}
