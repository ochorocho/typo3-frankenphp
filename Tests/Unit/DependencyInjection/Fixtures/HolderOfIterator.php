<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final readonly class HolderOfIterator
{
    /** @param iterable<object> $items */
    public function __construct(public iterable $items) {}
}
