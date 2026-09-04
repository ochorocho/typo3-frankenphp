<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures;

use TYPO3\CMS\Core\Core\RequestId;

final readonly class RequestIdConsumer
{
    public function __construct(public RequestId $requestId) {}
}
