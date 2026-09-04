<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final readonly class SecondHolderOfPrototype
{
    public function __construct(public PrototypeService $dependency) {}
}
