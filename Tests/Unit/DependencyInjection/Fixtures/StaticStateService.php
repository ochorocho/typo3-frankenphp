<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final class StaticStateService
{
    /** @var list<string> */
    public static array $queue = [];
}
