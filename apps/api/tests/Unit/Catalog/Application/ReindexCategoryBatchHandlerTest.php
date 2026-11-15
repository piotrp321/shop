<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CategoryReindexRun;
use App\Catalog\Application\Command\ReindexCategoryBatch;
use App\Catalog\Application\Command\ReindexCategoryBatchHandler;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use App\Tests\Support\InMemory\RecordingProductIndexer;
use App\Tests\Unit\Catalog\Search\InMemoryProductSearchSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * One batch of the chain that reindexes a category subtree: the run is locked first, a batch other than the next one
 * or of a finished run changes nothing, a batch reindexes the next products of the subtree after the cursor, moves
 * the run on and sends the next batch, and the empty batch finishes the run and invalidates the catalog again.
 */
final class ReindexCategoryBatchHandlerTest extends TestCase
{
    private const string RUN = '00000000-0000-4000-8000-000000000001';
    private const string CATEGORY = 'c0000000-0000-4000-8000-000000000001';
    private const string OTHER_CATEGORY = 'c0000000-0000-4000-8000-000000000002';
    private const array SUBTREE = [
        'a0000000-0000-4000-8000-000000000001',
        'a0000000-0000-4000-8000-000000000002',
        'a0000000-0000-4000-8000-000000000003',
        'a0000000-0000-4000-8000-000000000004',
        'a0000000-0000-4000-8000-000000000005',
    ];
    private const string OUTSIDE = 'b0000000-0000-4000-8000-000000000001';

    private InMemoryCategoryReindexRuns $runs;
    private InMemorySubtreeProductIds $subtrees;
    private RecordingProductIndexer $indexer;
    private InMemoryProductOfferFacts $offers;
    private InMemoryProductSearchSource $source;
    private RecordingCategoryReindexBatches $batches;
    private SpyStorefrontCatalogCache $cache;
    private FixedCatalogClock $clock;

    protected function setUp(): void
    {
        $this->runs = new InMemoryCategoryReindexRuns();
        $this->runs->runs[self::RUN] = new CategoryReindexRun(self::RUN, self::CATEGORY, null, 0, false);
        $this->subtrees = new InMemorySubtreeProductIds([self::CATEGORY => self::SUBTREE, self::OTHER_CATEGORY => [self::OUTSIDE]]);
        $this->indexer = new RecordingProductIndexer();
        $this->offers = new InMemoryProductOfferFacts();
        $this->source = new InMemoryProductSearchSource(array_map(self::snapshot(...), [...self::SUBTREE, self::OUTSIDE]));
        $this->batches = new RecordingCategoryReindexBatches();
        $this->cache = new SpyStorefrontCatalogCache();
        $this->clock = new FixedCatalogClock();
    }

    public function testABatchReindexesTheNextProductsOfTheSubtreeMovesTheRunOnAndSendsTheNextBatch(): void
    {
        $this->handle(0);

        self::assertSame([self::SUBTREE[0], self::SUBTREE[1]], $this->indexedIds());
        self::assertEquals(new CategoryReindexRun(self::RUN, self::CATEGORY, self::SUBTREE[1], 1, false), $this->runs->runs[self::RUN]);
        self::assertSame([[self::RUN, 1]], $this->batches->sent);
        self::assertSame(0, $this->cache->invalidations);
        self::assertSame('lock', $this->runs->calls[0], 'the run is locked before anything else');
    }

    public function testTheNextBatchContinuesAfterTheCursor(): void
    {
        $this->handle(0);
        $this->handle(1);

        self::assertSame(\array_slice(self::SUBTREE, 0, 4), $this->indexedIds());
        self::assertEquals(new CategoryReindexRun(self::RUN, self::CATEGORY, self::SUBTREE[3], 2, false), $this->runs->runs[self::RUN]);
        self::assertSame([[self::RUN, 1], [self::RUN, 2]], $this->batches->sent);
    }

    public function testTheEmptyBatchFinishesTheRunAndInvalidatesTheCatalogAgainWithoutANextBatch(): void
    {
        foreach ([0, 1, 2, 3] as $batch) {
            $this->handle($batch);
        }

        self::assertSame(self::SUBTREE, $this->indexedIds(), 'every product of the subtree once, none outside it');
        self::assertTrue($this->runs->runs[self::RUN]->finished);
        self::assertEquals($this->clock->now(), $this->runs->finishedAt[self::RUN]);
        self::assertSame([[self::RUN, 1], [self::RUN, 2], [self::RUN, 3]], $this->batches->sent);
        self::assertSame(1, $this->cache->invalidations);
    }

    public function testAnotherCopyOfABatchAlreadyDoneChangesNothing(): void
    {
        $this->handle(0);
        $before = $this->runs->runs[self::RUN];

        $this->handle(0);

        self::assertCount(2, $this->indexer->indexed);
        self::assertSame($before, $this->runs->runs[self::RUN]);
        self::assertSame([[self::RUN, 1]], $this->batches->sent);
    }

    public function testABatchAheadOfTheNextOneChangesNothing(): void
    {
        $this->handle(1);

        self::assertSame([], $this->indexer->indexed);
        self::assertEquals(new CategoryReindexRun(self::RUN, self::CATEGORY, null, 0, false), $this->runs->runs[self::RUN]);
        self::assertSame([], $this->batches->sent);
    }

    public function testADeliveryAfterTheRunFinishedChangesNothing(): void
    {
        $this->runs->runs[self::RUN] = new CategoryReindexRun(self::RUN, self::CATEGORY, self::SUBTREE[4], 3, true);

        $this->handle(3);

        self::assertSame([], $this->indexer->indexed);
        self::assertSame([], $this->batches->sent);
        self::assertSame(0, $this->cache->invalidations);
        self::assertSame([], $this->runs->finishedAt, 'the finish is not written again');
    }

    public function testABatchOfARunThatDoesNotExistChangesNothing(): void
    {
        $this->handler()(new ReindexCategoryBatch('00000000-0000-4000-8000-000000000999', 0));

        self::assertSame([], $this->indexer->indexed);
        self::assertSame([], $this->batches->sent);
        self::assertSame(['lock'], $this->runs->calls);
    }

    public function testAProductTheDatabaseNoLongerHasIsPassedOver(): void
    {
        $this->source = new InMemoryProductSearchSource([self::snapshot(self::SUBTREE[1])]);

        $this->handle(0);

        self::assertSame([self::SUBTREE[1]], $this->indexedIds());
        self::assertEquals(new CategoryReindexRun(self::RUN, self::CATEGORY, self::SUBTREE[1], 1, false), $this->runs->runs[self::RUN]);
        self::assertSame([[self::RUN, 1]], $this->batches->sent);
    }

    public function testATransientFailureOfTheIndexIsLeftToTheRetriesBeforeTheRunMovesOn(): void
    {
        $this->indexer->willReturn(new SearchUnavailable('Elasticsearch is down.'));

        try {
            $this->handle(0);
            self::fail('The failure of the index was swallowed.');
        } catch (SearchUnavailable) {
        }

        self::assertNotContains('advance', $this->runs->calls);
        self::assertSame([], $this->batches->sent);
    }

    public function testAFailureOfTheSearchOrTheOffersIsLeftToTheRetries(): void
    {
        $this->subtrees->failure = new SearchUnavailable('Elasticsearch is down.');
        try {
            $this->handle(0);
            self::fail('The failure of the search was swallowed.');
        } catch (SearchUnavailable) {
        }

        $this->subtrees->failure = null;
        $this->offers->failure = new OfferFactsUnavailable('Reading the product offers failed.');
        try {
            $this->handle(0);
            self::fail('The failure of the offers was swallowed.');
        } catch (OfferFactsUnavailable) {
        }

        self::assertNotContains('advance', $this->runs->calls);
        self::assertSame([], $this->batches->sent);
    }

    public function testAPermanentFailureOfTheIndexGoesToFailedWithoutRetries(): void
    {
        $failure = new SearchIndexError('Mapping rejected.');
        $this->indexer->willReturn($failure);

        try {
            $this->handle(0);
            self::fail('The permanent failure was swallowed.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertSame([], $this->batches->sent);
    }

    public function testAFailedInvalidationAtTheEndIsThrownSoTheFinishIsRolledBackAndRetried(): void
    {
        $this->runs->runs[self::RUN] = new CategoryReindexRun(self::RUN, self::CATEGORY, self::SUBTREE[4], 3, false);
        $this->cache->failure = new \RuntimeException('Varnish is down.');

        $this->expectExceptionObject($this->cache->failure);

        $this->handle(3);
    }

    private function handle(int $batch): void
    {
        $this->handler()(new ReindexCategoryBatch(self::RUN, $batch));
    }

    private function handler(): ReindexCategoryBatchHandler
    {
        return new ReindexCategoryBatchHandler(
            $this->runs,
            $this->subtrees,
            new ProductIndexing($this->source, $this->offers, $this->indexer, new ProductDocumentBuilder(), $this->clock),
            $this->batches,
            $this->cache,
            $this->clock,
            2,
        );
    }

    /**
     * @return list<string>
     */
    private function indexedIds(): array
    {
        return array_map(static fn (ProductDocument $document): string => $document->id, $this->indexer->indexed);
    }

    private static function snapshot(string $id): ProductSearchSnapshot
    {
        return new ProductSearchSnapshot($id, 'Buty biegowe', 'buty-'.substr($id, -2), 'Lekkie', self::CATEGORY, [], [[self::CATEGORY]], null, ['Buty'], [], [], ProductStatus::Published);
    }
}
