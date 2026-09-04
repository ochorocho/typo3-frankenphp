<?php

declare(strict_types=1);

namespace Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection;

use Ochorocho\FrankenPhp\DependencyInjection\WorkerKeepListPass;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\DependsOnPinConflictRoot;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\HolderOfInlinedMutable;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\HolderOfIterator;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\HolderOfMutable;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\HolderOfPrototype;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\MutableService;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\PinConflictRoot;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\PinnedDependency;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\PinnedRoot;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\PrototypeService;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\ReadonlyPropsOnly;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\RequestIdConsumer;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\SecondHolderOfPrototype;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\StatelessReadonly;
use Ochorocho\FrankenPhp\Tests\Unit\DependencyInjection\Fixtures\StaticStateService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Core\Core\RequestId;

final class WorkerKeepListPassTest extends TestCase
{
    /** @var array<string, array{category: string, reason: string, class?: string}> */
    private array $report;

    /** @var list<string> */
    private array $keep;

    protected function setUp(): void
    {
        $builder = new ContainerBuilder();

        // Mirror TYPO3's synthetic early service + public alias for RequestId.
        $builder->register(WorkerKeepListPass::REQUEST_ID_SERVICE)->setSynthetic(true)->setPublic(true);
        $builder->setAlias(RequestId::class, WorkerKeepListPass::REQUEST_ID_SERVICE)->setPublic(true);

        $this->register($builder, StatelessReadonly::class);
        $this->register($builder, MutableService::class);
        $this->register($builder, ReadonlyPropsOnly::class, [new Reference(StatelessReadonly::class)]);
        $this->register($builder, HolderOfMutable::class, [new Reference(MutableService::class)]);
        $this->register($builder, RequestIdConsumer::class, [new Reference(RequestId::class)]);
        $this->register($builder, PrototypeService::class)->setShared(false);
        // Symfony inlines non-shared services into every consumer, so the
        // holders end up owning a private mutable object each.
        $this->register($builder, HolderOfPrototype::class, [new Reference(PrototypeService::class)]);
        $this->register($builder, SecondHolderOfPrototype::class, [new Reference(PrototypeService::class)]);
        $this->register($builder, HolderOfIterator::class, [new IteratorArgument([new Reference(MutableService::class)])]);
        $this->register($builder, PinnedDependency::class);
        $this->register($builder, PinnedRoot::class, [new Reference(PinnedDependency::class)]);
        $this->register($builder, 'pinned.collector', [new IteratorArgument([new Reference(MutableService::class)])], HolderOfIterator::class);
        $this->register($builder, PinConflictRoot::class, [new Reference(RequestId::class)]);
        $this->register($builder, DependsOnPinConflictRoot::class, [new Reference(PinConflictRoot::class)]);
        $this->register($builder, HolderOfInlinedMutable::class, [new Definition(MutableService::class)]);
        $this->register($builder, StaticStateService::class);
        $this->register($builder, 'cache.fixture', [], \ArrayObject::class);
        $this->register($builder, 'tagged.keep', [], MutableService::class)->addTag(WorkerKeepListPass::TAG_KEEP);
        $this->register($builder, 'tagged.discard', [], StatelessReadonly::class)->addTag(WorkerKeepListPass::TAG_DISCARD);

        $builder->addCompilerPass(
            new WorkerKeepListPass(
                pinnedServices: [PinnedRoot::class, PinConflictRoot::class, 'pinned.collector'],
                softServices: [],
                discardServices: [],
                discardPatterns: [],
                keepIdPatterns: ['/^cache\./' => 'cache-frontend'],
            ),
            PassConfig::TYPE_AFTER_REMOVING,
            -1024
        );
        $builder->compile();

        /** @var array<string, array{category: string, reason: string, class?: string}> $report */
        $report = $builder->getParameter(WorkerKeepListPass::PARAMETER_REPORT);
        $this->report = $report;
        /** @var list<string> $keep */
        $keep = $builder->getParameter(WorkerKeepListPass::PARAMETER_KEEP);
        $this->keep = $keep;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function register(ContainerBuilder $builder, string $id, array $arguments = [], ?string $class = null): Definition
    {
        return $builder->register($id, $class ?? $id)->setArguments($arguments)->setPublic(true);
    }

    #[Test]
    public function readonlyClassIsKept(): void
    {
        self::assertContains(StatelessReadonly::class, $this->keep);
        self::assertSame('readonly', $this->report[StatelessReadonly::class]['reason']);
    }

    #[Test]
    public function classWithOnlyReadonlyPropertiesIsKept(): void
    {
        self::assertContains(ReadonlyPropsOnly::class, $this->keep);
        self::assertSame('readonly-props', $this->report[ReadonlyPropsOnly::class]['reason']);
    }

    #[Test]
    public function mutableClassIsDiscardedWithPropertyNames(): void
    {
        self::assertNotContains(MutableService::class, $this->keep);
        self::assertSame('mutable', $this->report[MutableService::class]['reason']);
        self::assertArrayNotHasKey('class', $this->report[MutableService::class]);
    }

    #[Test]
    public function readonlyHolderOfDiscardedServiceIsDemoted(): void
    {
        self::assertNotContains(HolderOfMutable::class, $this->keep);
        self::assertSame(
            'demoted-via:' . MutableService::class . ' (discard)',
            $this->report[HolderOfMutable::class]['reason']
        );
    }

    #[Test]
    public function requestIdConsumerIsDemoted(): void
    {
        self::assertNotContains(RequestIdConsumer::class, $this->keep);
        self::assertSame(
            'demoted-via:' . WorkerKeepListPass::REQUEST_ID_SERVICE . ' (request-id)',
            $this->report[RequestIdConsumer::class]['reason']
        );
    }

    #[Test]
    public function nonSharedServicesAreNotClassifiedAndTheirHoldersAreDiscarded(): void
    {
        self::assertArrayNotHasKey(PrototypeService::class, $this->report);
        $expected = 'inlined-mutable:' . PrototypeService::class;
        self::assertSame($expected, $this->report[HolderOfPrototype::class]['reason']);
        self::assertSame($expected, $this->report[SecondHolderOfPrototype::class]['reason']);
    }

    #[Test]
    public function lazyIteratorReferencesDoNotDemote(): void
    {
        self::assertContains(HolderOfIterator::class, $this->keep);
    }

    #[Test]
    public function pinnedRootKeepsItsMutableDependencyClosure(): void
    {
        self::assertContains(PinnedRoot::class, $this->keep);
        self::assertSame('pinned', $this->report[PinnedRoot::class]['reason']);
        self::assertContains(PinnedDependency::class, $this->keep);
        self::assertSame('pinned-via:' . PinnedRoot::class, $this->report[PinnedDependency::class]['reason']);
    }

    #[Test]
    public function pinnedHolderOfLazyCollectionIsFlagged(): void
    {
        self::assertContains('pinned.collector', $this->keep);
        self::assertSame('pinned (holds lazily collected instances)', $this->report['pinned.collector']['reason']);
    }

    #[Test]
    public function pinnedRootReachingRequestIdIsReportedAsConflictAndDiscarded(): void
    {
        self::assertNotContains(PinConflictRoot::class, $this->keep);
        self::assertSame(
            sprintf('pin-conflict:%s -> %s (request-id)', PinConflictRoot::class, WorkerKeepListPass::REQUEST_ID_SERVICE),
            $this->report[PinConflictRoot::class]['reason']
        );
        self::assertNotContains(DependsOnPinConflictRoot::class, $this->keep);
    }

    #[Test]
    public function readonlyHolderOfInlinedMutableDefinitionIsDiscarded(): void
    {
        self::assertNotContains(HolderOfInlinedMutable::class, $this->keep);
        self::assertSame('inlined-mutable:' . MutableService::class, $this->report[HolderOfInlinedMutable::class]['reason']);
    }

    #[Test]
    public function staticPropertiesDoNotAffectKeep(): void
    {
        self::assertContains(StaticStateService::class, $this->keep);
        self::assertSame('readonly-props', $this->report[StaticStateService::class]['reason']);
    }

    #[Test]
    public function keepIdPatternOverridesOpaqueClassification(): void
    {
        self::assertContains('cache.fixture', $this->keep);
        self::assertSame('cache-frontend', $this->report['cache.fixture']['reason']);
        self::assertSame(\ArrayObject::class, $this->report['cache.fixture']['class']);
    }

    #[Test]
    public function tagsOverrideIntrinsicClassification(): void
    {
        self::assertContains('tagged.keep', $this->keep);
        self::assertSame('tag', $this->report['tagged.keep']['reason']);
        self::assertNotContains('tagged.discard', $this->keep);
        self::assertSame('tag', $this->report['tagged.discard']['reason']);
    }

    #[Test]
    public function syntheticServicesAreNotClassified(): void
    {
        self::assertArrayNotHasKey(WorkerKeepListPass::REQUEST_ID_SERVICE, $this->report);
    }
}
