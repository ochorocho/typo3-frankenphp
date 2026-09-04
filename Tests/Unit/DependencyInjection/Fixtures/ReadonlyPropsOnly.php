<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

final class ReadonlyPropsOnly
{
    public function __construct(public readonly StatelessReadonly $dependency) {}
}
