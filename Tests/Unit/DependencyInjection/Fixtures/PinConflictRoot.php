<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

use TYPO3\CMS\Core\Core\RequestId;

final class PinConflictRoot
{
    public int $counter = 0;

    public function __construct(public RequestId $requestId) {}
}
