<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final readonly class HolderOfInlinedMutable
{
    public function __construct(public MutableService $dependency) {}
}
