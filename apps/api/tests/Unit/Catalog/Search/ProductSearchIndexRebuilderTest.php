<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Search\ActivationResult;
use App\Catalog\Application\Search\BulkResult;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Application\Search\VariantFacts;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductSearchIndexRebuilderTest extends TestCase
{
    private FakeSearchIndexMaintenance $maintenance;
    private FakeSearchIndexRebuildLock $lock;
    private InMemoryProductSearchSource $source;
    private InMemoryProductOfferFacts $offers;
    private FixedCatalogClock $clock;

    protected function setUp(): void
    {
        $this->maintenance = new FakeSearchIndexMaintenance('products_old', 'products_new');
        $this->lock = new FakeSearchIndexRebuildLock();
        $this->source = new InMemoryProductSearchSource(self::snapshots(7));
        $this->offers = new InMemoryProductOfferFacts();
        $this->clock = new FixedCatalogClock(new \DateTimeImmutable('2026-11-06T12:00:00.000001+00:00'));
    }

    public function testEachBatchCarriesTheOfferFactsOfItsProducts(): void
    {
        $ids = array_map(static fn (ProductSearchSnapshot $s): string => $s->id, self::snapshots(7));
        $this->offers->facts = [
            $ids[0] => new ProductFacts(4_900, [self::variantOf($ids[0]) => new VariantFacts(4_900, true)]),
            $ids[6] => new ProductFacts(null, [self::variantOf($ids[6]) => new VariantFacts(9_900, false)]),
        ];

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::Activated, $report->outcome);
        self::assertSame([\array_slice($ids, 0, 3), \array_slice($ids, 3, 3), \array_slice($ids, 6)], $this->offers->asked);
        $documents = array_merge(...$this->maintenance->written['products_new']);
        self::assertSame(4_900, $documents[0]->fromGross);
        self::assertEquals([new VariantDocument(4_900, true, [])], $documents[0]->variants);
        // Without facts: no price and nothing to buy.
        self::assertNull($documents[1]->fromGross);
        self::assertEquals([new VariantDocument(null, false, [])], $documents[1]->variants);
        self::assertNull($documents[6]->fromGross);
        self::assertEquals([new VariantDocument(9_900, false, [])], $documents[6]->variants);
    }

    public function testEveryBatchHasTheTimeTakenBeforeItsReadsAsTheVersion(): void
    {
        // Both reads of a batch take time: the one of the products and the one of their offers.
        $this->source->onRead = fn () => $this->clock->advance('+1 second');
        $this->offers->onRead = fn () => $this->clock->advance('+1 second');

        $this->rebuilder()->rebuild(3);

        self::assertSame(
            [[1_793_966_400_000_001], [1_793_966_402_000_001], [1_793_966_404_000_001]],
            array_map(
                static fn (array $batch): array => array_values(array_unique(array_map(static fn (ProductDocument $d): int => $d->version, $batch))),
                $this->maintenance->written['products_new'],
            ),
        );
    }

    public function testAFailureOfTheOfferFactsStopsTheRebuildAndKeepsTheAlias(): void
    {
        $this->offers->failure = new OfferFactsUnavailable('Reading the product offers failed: connection lost.');

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::NotActivated, $report->outcome);
        self::assertStringContainsString('connection lost', (string) $report->failure);
        self::assertSame([], $this->maintenance->written['products_new'] ?? []);
        self::assertSame([], $this->maintenance->activations);
        self::assertSame(['products_new'], $this->maintenance->deleted);
        $this->assertLockReleased();
    }

    public function testBusyLockChangesNothing(): void
    {
        $this->lock = new FakeSearchIndexRebuildLock(takenElsewhere: true);

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::NotActivated, $report->outcome);
        self::assertNotNull($report->failure);
        self::assertSame([], $this->maintenance->calls);
        self::assertSame([], $this->source->calls);
        self::assertSame(0, $this->lock->releases, 'A lock that was not acquired is not released.');
    }

    public function testSuccessfulRebuildSwitchesTheAliasAndDeletesTheOldIndex(): void
    {
        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::Activated, $report->outcome);
        self::assertSame('products_old', $report->previousIndex);
        self::assertSame('products_new', $report->newIndex);
        self::assertSame(7, $report->documents);
        self::assertSame([], $report->warnings);
        self::assertSame([['products_new', 7]], $this->maintenance->verified);
        self::assertSame([['products_new', 'products_old']], $this->maintenance->activations);
        self::assertSame(['products_old'], $this->maintenance->deleted);
        $this->assertLockReleased();
    }

    public function testReadsBatchesWithTheAfterIdCursorUntilAnEmptyPage(): void
    {
        $ids = array_map(static fn (ProductSearchSnapshot $s): string => $s->id, self::snapshots(7));

        $this->rebuilder()->rebuild(3);

        self::assertSame([[null, 3], [$ids[2], 3], [$ids[5], 3], [$ids[6], 3]], $this->source->calls);
        self::assertSame(
            [\array_slice($ids, 0, 3), \array_slice($ids, 3, 3), \array_slice($ids, 6)],
            array_map(
                static fn (array $batch): array => array_map(static fn (ProductDocument $d): string => $d->id, $batch),
                $this->maintenance->written['products_new'],
            ),
        );
    }

    public function testFailureToReadTheAliasCreatesNothing(): void
    {
        $this->maintenance->activeIndexFailure = new SearchIndexError('The alias is missing.');

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::NotActivated, $report->outcome);
        self::assertSame(['activeIndex'], $this->maintenance->calls);
        $this->assertLockReleased();
    }

    /**
     * @return iterable<string, array{\Closure(FakeSearchIndexMaintenance): void}>
     */
    public static function failuresBeforeActivation(): iterable
    {
        yield 'bulk request fails' => [static function (FakeSearchIndexMaintenance $m): void {
            $m->writeFailure = new SearchUnavailable('Elasticsearch is unavailable.');
        }];
        yield 'bulk item stale' => [static function (FakeSearchIndexMaintenance $m): void {
            $m->writeResult = new BulkResult(indexed: ['a', 'b'], stale: ['c']);
        }];
        yield 'bulk item transient failure' => [static function (FakeSearchIndexMaintenance $m): void {
            $m->writeResult = new BulkResult(indexed: ['a', 'b'], transientFailures: ['c' => 'es_rejected_execution_exception']);
        }];
        yield 'bulk item permanent failure' => [static function (FakeSearchIndexMaintenance $m): void {
            $m->writeResult = new BulkResult(indexed: ['a', 'b'], permanentFailures: ['c' => 'document_parsing_exception']);
        }];
        yield 'verification fails' => [static function (FakeSearchIndexMaintenance $m): void {
            $m->verifyFailure = new SearchIndexError('Expected 7 documents, found 6.');
        }];
    }

    /**
     * @param \Closure(FakeSearchIndexMaintenance): void $breakIt
     */
    #[DataProvider('failuresBeforeActivation')]
    public function testFailureBeforeActivationDeletesTheNewIndexAndKeepsTheAlias(\Closure $breakIt): void
    {
        $breakIt($this->maintenance);

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::NotActivated, $report->outcome);
        self::assertNotNull($report->failure);
        self::assertSame('products_new', $report->newIndex);
        self::assertSame([], $this->maintenance->activations);
        self::assertSame(['products_new'], $this->maintenance->deleted);
        $this->assertLockReleased();
    }

    public function testNotActivatedDeletesTheNewIndex(): void
    {
        $this->maintenance->activationResult = ActivationResult::NotActivated;

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::NotActivated, $report->outcome);
        self::assertNotNull($report->failure);
        self::assertSame(['products_new'], $this->maintenance->deleted);
        $this->assertLockReleased();
    }

    public function testUnknownActivationDeletesNothingAndNamesBothIndexes(): void
    {
        $this->maintenance->activationResult = ActivationResult::Unknown;

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::Unknown, $report->outcome);
        self::assertSame('products_old', $report->previousIndex);
        self::assertSame('products_new', $report->newIndex);
        self::assertSame([], $this->maintenance->deleted);
        $this->assertLockReleased();
    }

    public function testFailureToDeleteTheOldIndexAfterActivationIsAWarning(): void
    {
        $this->maintenance->undeletable = ['products_old'];

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::Activated, $report->outcome);
        self::assertNull($report->failure);
        self::assertCount(1, $report->warnings);
        self::assertStringContainsString('products_old', $report->warnings[0]);
        $this->assertLockReleased();
    }

    public function testFailureToDeleteTheNewIndexAfterAFailureIsAWarningOnTheFailedReport(): void
    {
        $this->maintenance->verifyFailure = new SearchIndexError('Expected 7 documents, found 6.');
        $this->maintenance->undeletable = ['products_new'];

        $report = $this->rebuilder()->rebuild(3);

        self::assertSame(ActivationResult::NotActivated, $report->outcome);
        self::assertCount(1, $report->warnings);
        self::assertStringContainsString('products_new', $report->warnings[0]);
        $this->assertLockReleased();
    }

    public function testUnexpectedErrorDeletesTheNewIndexReleasesTheLockAndIsRethrown(): void
    {
        $this->maintenance->writeFailure = new \LogicException('bug');

        try {
            $this->rebuilder()->rebuild(3);
            self::fail('The exception was swallowed.');
        } catch (\LogicException $e) {
            self::assertSame('bug', $e->getMessage());
        }

        self::assertSame(['products_new'], $this->maintenance->deleted);
        self::assertSame([], $this->maintenance->activations);
        $this->assertLockReleased();
    }

    public function testBatchSizeMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->rebuilder()->rebuild(0);
    }

    public function testResetSwitchesTheAliasToAnEmptyIndex(): void
    {
        $this->rebuilder()->reset();

        self::assertSame([], $this->source->calls);
        self::assertSame([], $this->maintenance->written);
        self::assertSame([['products_new', 0]], $this->maintenance->verified);
        self::assertSame([['products_new', 'products_old']], $this->maintenance->activations);
        self::assertSame(['products_old'], $this->maintenance->deleted);
        $this->assertLockReleased();
    }

    public function testResetThatDoesNotSwitchTheAliasThrows(): void
    {
        $this->maintenance->activationResult = ActivationResult::NotActivated;

        try {
            $this->rebuilder()->reset();
            self::fail('reset() reported success.');
        } catch (SearchIndexError) {
        }

        self::assertSame(['products_new'], $this->maintenance->deleted);
        $this->assertLockReleased();
    }

    public function testResetWithABusyLockThrowsAndChangesNothing(): void
    {
        $this->lock = new FakeSearchIndexRebuildLock(takenElsewhere: true);

        try {
            $this->rebuilder()->reset();
            self::fail('reset() reported success.');
        } catch (SearchIndexError) {
        }

        self::assertSame([], $this->maintenance->calls);
    }

    private function rebuilder(): ProductSearchIndexRebuilder
    {
        return new ProductSearchIndexRebuilder($this->source, $this->maintenance, $this->lock, new ProductDocumentBuilder(), $this->offers, $this->clock);
    }

    private function assertLockReleased(): void
    {
        self::assertFalse($this->lock->held);
        self::assertSame(1, $this->lock->releases);
    }

    /**
     * @return list<ProductSearchSnapshot>
     */
    private static function snapshots(int $count): array
    {
        $category = '00000000-0000-4000-8000-0000000000c1';
        $snapshots = [];
        for ($i = 1; $i <= $count; ++$i) {
            $snapshots[] = new ProductSearchSnapshot(
                \sprintf('00000000-0000-4000-8000-%012d', $i),
                'Product '.$i,
                'product-'.$i,
                '',
                $category,
                [],
                [[$category]],
                null,
                [],
                [],
                [['id' => self::variantOf(\sprintf('00000000-0000-4000-8000-%012d', $i)), 'values' => []]],
            );
        }

        return $snapshots;
    }

    private static function variantOf(string $productId): string
    {
        return '1'.substr($productId, 1);
    }
}
