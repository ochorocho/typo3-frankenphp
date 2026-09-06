<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker\Fixtures;

use TYPO3\CMS\Core\Core\ApplicationInterface;

final class RecordingApplication implements ApplicationInterface
{
    public int $runs = 0;

    public function run(): void
    {
        $this->runs++;
    }
}
