<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Worker;

use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\ApplicationInterface;

/**
 * Drives the reset around one worker request.
 *
 * `begin()` runs the clock-bound part of the reset and hands out the
 * Application; `finish()` flushes the response to the client with
 * `frankenphp_finish_request()` and then does the structural reset and the
 * Application lookup for the next request while no client is waiting.
 * If that post-response phase is unavailable or fails, the next `begin()`
 * falls back to the full inline reset, so a half-reset worker never serves
 * a request.
 */
final class WorkerRequestCycle
{
    public const string MODE_INLINE = 'inline';
    public const string MODE_POST = 'post';

    private ?ApplicationInterface $nextApplication = null;
    private string $mode = self::MODE_INLINE;
    private int $discarded = 0;
    private int $resetMicroseconds = 0;
    private int $postResetMicroseconds = 0;
    private ?\Throwable $lastFailure = null;
    private string $strayOutput = '';

    /**
     * @param string $applicationId container id of the Application to run
     * @param (\Closure(): void)|null $finishRequest sends the response and returns; null when the SAPI has no such function
     * @param (\Closure(): void)|null $beforeStructuralReset development hook, runs while the finished request's state is still intact
     */
    public function __construct(
        private readonly WorkerStateResetter $resetter,
        private readonly WorkerStateSnapshot $snapshot,
        private readonly ContainerInterface $container,
        private readonly string $applicationId,
        private readonly ?\Closure $finishRequest,
        private readonly ?\Closure $beforeStructuralReset = null,
    ) {}

    /**
     * Prepares the worker for the request that just arrived.
     */
    public function begin(): ApplicationInterface
    {
        $start = hrtime(true);
        $application = $this->nextApplication;
        // Consumed now: a request that dies before finish() must not leave a
        // prepared-looking worker behind.
        $this->nextApplication = null;

        if ($application !== null) {
            $this->mode = self::MODE_POST;
            $this->resetter->beforeRequest($this->container);
        } else {
            $this->mode = self::MODE_INLINE;
            $this->discarded = $this->resetter->reset($this->snapshot, $this->container);
            $application = $this->application();
        }
        $this->resetMicroseconds = intdiv(hrtime(true) - $start, 1000);
        return $application;
    }

    /**
     * Sends the response, then resets for the next request.
     */
    public function finish(): void
    {
        if ($this->finishRequest === null) {
            return;
        }
        ($this->finishRequest)();

        // Headers are sent; anything written now would corrupt nothing but
        // must not reach the client either.
        ob_start();
        $start = hrtime(true);
        try {
            if ($this->beforeStructuralReset !== null) {
                ($this->beforeStructuralReset)();
            }
            $this->discarded = $this->resetter->afterResponse($this->snapshot, $this->container);
            $this->nextApplication = $this->application();
            $this->lastFailure = null;
        } catch (\Throwable $e) {
            $this->nextApplication = null;
            $this->lastFailure = $e;
        } finally {
            $this->postResetMicroseconds = intdiv(hrtime(true) - $start, 1000);
            $this->strayOutput = (string)ob_get_clean();
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Service instances the previous request left behind.
     */
    public function discarded(): int
    {
        return $this->discarded;
    }

    /**
     * Reset time inside the client-visible latency of the current request.
     */
    public function resetMicroseconds(): int
    {
        return $this->resetMicroseconds;
    }

    /**
     * Reset time spent after the previous response, invisible to clients.
     */
    public function postResetMicroseconds(): int
    {
        return $this->postResetMicroseconds;
    }

    public function lastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }

    public function strayOutput(): string
    {
        return $this->strayOutput;
    }

    private function application(): ApplicationInterface
    {
        $application = $this->container->get($this->applicationId);
        if (!$application instanceof ApplicationInterface) {
            throw new \RuntimeException(sprintf('Service "%s" is not a TYPO3 application.', $this->applicationId), 1757100001);
        }
        return $application;
    }
}
