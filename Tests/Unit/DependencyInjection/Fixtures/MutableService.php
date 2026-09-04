<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final class MutableService
{
    /** @var list<string> */
    public array $items = [];
}
