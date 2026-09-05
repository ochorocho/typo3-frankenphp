<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

/**
 * Registered under a custom service id only, never under its class name.
 */
final class CustomIdService
{
    /** @var list<string> */
    public array $items = [];
}
