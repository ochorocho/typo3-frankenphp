<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection;

use Ochorocho\FrankenPhp\DependencyInjection\ClassStateAnalyzer;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\MutableService;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\ReadonlyPropsOnly;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\StatelessReadonly;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\StaticStateService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClassStateAnalyzerTest extends TestCase
{
    private ClassStateAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ClassStateAnalyzer();
    }

    #[Test]
    public function readonlyClassIsStateless(): void
    {
        $analysis = $this->analyzer->analyze(StatelessReadonly::class);
        self::assertTrue($analysis['stateless']);
        self::assertSame(ClassStateAnalyzer::REASON_READONLY, $analysis['reason']);
    }

    #[Test]
    public function readonlyPropertiesOnlyIsStateless(): void
    {
        $analysis = $this->analyzer->analyze(ReadonlyPropsOnly::class);
        self::assertTrue($analysis['stateless']);
        self::assertSame(ClassStateAnalyzer::REASON_READONLY_PROPS, $analysis['reason']);
    }

    #[Test]
    public function mutablePropertiesAreListed(): void
    {
        $analysis = $this->analyzer->analyze(MutableService::class);
        self::assertFalse($analysis['stateless']);
        self::assertSame(['items'], $analysis['mutable']);
        self::assertSame('mutable: items', $this->analyzer->describeProperties(MutableService::class));
    }

    #[Test]
    public function staticPropertiesAreReportedWithoutBlockingStateless(): void
    {
        $analysis = $this->analyzer->analyze(StaticStateService::class);
        self::assertTrue($analysis['stateless']);
        self::assertSame(['queue'], $analysis['static']);
        self::assertSame('static: queue', $this->analyzer->describeProperties(StaticStateService::class));
    }

    #[Test]
    public function interfacesUnknownAndMissingClassesAreNotStateless(): void
    {
        self::assertSame(ClassStateAnalyzer::REASON_OPAQUE, $this->analyzer->analyze(\Countable::class)['reason']);
        self::assertSame(ClassStateAnalyzer::REASON_OPAQUE, $this->analyzer->analyze('object')['reason']);
        self::assertSame(ClassStateAnalyzer::REASON_OPAQUE, $this->analyzer->analyze(null)['reason']);
        self::assertSame(ClassStateAnalyzer::REASON_UNLOADABLE, $this->analyzer->analyze('Does\\Not\\Exist')['reason']);
    }
}
