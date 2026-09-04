<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final class PinnedRoot
{
    public int $counter = 0;

    public function __construct(public PinnedDependency $dependency) {}
}
