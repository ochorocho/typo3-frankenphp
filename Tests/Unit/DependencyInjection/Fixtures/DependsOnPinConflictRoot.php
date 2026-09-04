<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final readonly class DependsOnPinConflictRoot
{
    public function __construct(public PinConflictRoot $dependency) {}
}
