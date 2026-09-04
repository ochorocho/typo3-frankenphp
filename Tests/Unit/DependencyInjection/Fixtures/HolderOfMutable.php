<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final readonly class HolderOfMutable
{
    public function __construct(public MutableService $dependency) {}
}
