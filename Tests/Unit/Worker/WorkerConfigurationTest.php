<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\Worker;

use Ochorocho\FrankenPhp\Worker\WorkerConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkerConfigurationTest extends TestCase
{
    #[Test]
    public function fromArrayAttributesEveryEntryToItsOrigin(): void
    {
        $configuration = WorkerConfiguration::fromArray([
            'pin' => ['\\Vendor\\Ext\\Registry'],
            'keep' => ['Vendor\\Ext\\Stateless'],
            'discard' => ['vendor.ext.legacy'],
            'discardPatterns' => ['/Controller$/'],
        ], 'my_ext', 'file.php');

        self::assertSame(['Vendor\\Ext\\Registry' => 'my_ext'], $configuration->pinned);
        self::assertSame(['Vendor\\Ext\\Stateless' => 'my_ext'], $configuration->keep);
        self::assertSame(['vendor.ext.legacy' => 'my_ext'], $configuration->discard);
        self::assertSame(['/Controller$/' => 'my_ext'], $configuration->discardPatterns);
    }

    #[Test]
    public function everyKeyIsOptional(): void
    {
        self::assertTrue(WorkerConfiguration::fromArray([], 'my_ext', 'file.php')->isEmpty());
    }

    #[Test]
    public function nonArrayReturnValueIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('file.php must return an array, string returned.');

        WorkerConfiguration::fromArray('nope', 'my_ext', 'file.php');
    }

    #[Test]
    public function unknownKeyIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('file.php: unknown key(s) "pinned"');

        WorkerConfiguration::fromArray(['pinned' => []], 'my_ext', 'file.php');
    }

    #[Test]
    public function nonListValueIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('file.php: "keep" must be a list of strings, string given.');

        WorkerConfiguration::fromArray(['keep' => 'Vendor\\Ext\\Stateless'], 'my_ext', 'file.php');
    }

    #[Test]
    public function emptyStringEntryIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('file.php: "discard" must only contain non-empty strings, an empty string found.');

        WorkerConfiguration::fromArray(['discard' => ['']], 'my_ext', 'file.php');
    }

    #[Test]
    public function nonStringEntryIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('file.php: "pin" must only contain non-empty strings, int found.');

        WorkerConfiguration::fromArray(['pin' => [42]], 'my_ext', 'file.php');
    }

    #[Test]
    public function invalidRegularExpressionIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('file.php: "/(/" in "discardPatterns" is not a valid regular expression.');

        WorkerConfiguration::fromArray(['discardPatterns' => ['/(/']], 'my_ext', 'file.php');
    }

    #[Test]
    public function mergeLetsTheLaterOriginWin(): void
    {
        $first = WorkerConfiguration::fromArray(['keep' => ['A', 'B']], 'ext_a', 'a.php');
        $second = WorkerConfiguration::fromArray(['keep' => ['B'], 'discard' => ['C']], 'ext_b', 'b.php');

        $merged = $first->merge($second);

        self::assertSame(['A' => 'ext_a', 'B' => 'ext_b'], $merged->keep);
        self::assertSame(['C' => 'ext_b'], $merged->discard);
    }

    #[Test]
    public function mergeCoversEveryList(): void
    {
        $first = WorkerConfiguration::fromArray(['pin' => ['P'], 'discardPatterns' => ['/a/']], 'ext_a', 'a.php');
        $second = WorkerConfiguration::fromArray(['pin' => ['Q'], 'discardPatterns' => ['/b/']], 'ext_b', 'b.php');

        $merged = $first->merge($second);

        self::assertSame(['P' => 'ext_a', 'Q' => 'ext_b'], $merged->pinned);
        self::assertSame(['/a/' => 'ext_a', '/b/' => 'ext_b'], $merged->discardPatterns);
        self::assertFalse($merged->isEmpty());
    }
}
