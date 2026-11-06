<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\ReindexOutcome;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Application\Search\VariantFacts;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use App\Tests\Support\InMemory\RecordingProductIndexer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductIndexingTest extends TestCase
{
    private const string PRODUCT_ID = '10000000-0000-4000-8000-000000000001';
    private const string VARIANT_ID = '20000000-0000-4000-8000-000000000001';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';

    private FixedCatalogClock $clock;
    private RecordingProductIndexer $indexer;
    private InMemoryProductOfferFacts $offers;
    private ProductSearchSource $source;

    protected function setUp(): void
    {
        $this->clock = new FixedCatalogClock();
        $this->indexer = new RecordingProductIndexer();
        $this->offers = new InMemoryProductOfferFacts([
            self::PRODUCT_ID => new ProductFacts(4_900, [self::VARIANT_ID => new VariantFacts(4_900, true)]),
        ]);
        $this->source = new InMemoryProductSearchSource([self::snapshot()]);
    }

    public function testWritesTheCurrentStateWithTheOfferFacts(): void
    {
        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex(self::PRODUCT_ID));

        self::assertSame([[self::PRODUCT_ID]], $this->offers->asked);
        self::assertCount(1, $this->indexer->indexed);
        $document = $this->indexer->indexed[0];
        self::assertSame(self::PRODUCT_ID, $document->id);
        self::assertSame(['Buty'], $document->categoryNames);
        self::assertEquals([new VariantDocument(4_900, true, [])], $document->variants);
        self::assertSame(4_900, $document->fromGross);
    }

    public function testTheVersionIsTheMicrosecondTimeTakenBeforeTheReads(): void
    {
        // Both reads take time; a read started later sees at least the same committed data, so it must win.
        $this->source = new class($this->source, $this->clock) implements ProductSearchSource {
            public function __construct(private readonly ProductSearchSource $inner, private readonly FixedCatalogClock $clock)
            {
            }

            public function page(?string $afterId, int $limit): array
            {
                return $this->inner->page($afterId, $limit);
            }

            public function find(string $id): ?ProductSearchSnapshot
            {
                $this->clock->advance('+1 second');

                return $this->inner->find($id);
            }
        };
        $this->offers->onRead = fn () => $this->clock->advance('+1 second');

        $this->indexing()->reindex(self::PRODUCT_ID);

        self::assertSame(1_793_966_400_123_456, $this->indexer->indexed[0]->version);
    }

    public function testAStaleWriteIsTheStaleOutcome(): void
    {
        $this->indexer->willReturn(IndexOutcome::Stale);

        self::assertSame(ReindexOutcome::Stale, $this->indexing()->reindex(self::PRODUCT_ID));
    }

    public function testAMissingProductIsProductNotFoundWithoutAskingTheOfferOrWriting(): void
    {
        $outcome = $this->indexing()->reindex('10000000-0000-4000-8000-0000000000ff');

        self::assertSame(ReindexOutcome::ProductNotFound, $outcome);
        self::assertSame([], $this->offers->asked);
        self::assertSame([], $this->indexer->indexed);
    }

    public function testAProductWithoutOfferFactsIsWrittenWithoutPricesAndNothingPurchasable(): void
    {
        $this->offers->facts = [];

        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex(self::PRODUCT_ID));

        $document = $this->indexer->indexed[0];
        self::assertEquals([new VariantDocument(null, false, [])], $document->variants);
        self::assertNull($document->fromGross);
    }

    public function testAFailureOfTheOfferIsPassedOnAndNothingIsWritten(): void
    {
        $this->offers->failure = new OfferFactsUnavailable('Connection lost.');

        try {
            $this->indexing()->reindex(self::PRODUCT_ID);
            self::fail('The failure was swallowed.');
        } catch (OfferFactsUnavailable $e) {
            self::assertSame($this->offers->failure, $e);
        }

        self::assertSame([], $this->indexer->indexed);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function searchFailures(): iterable
    {
        yield 'transient' => [new SearchUnavailable('Elasticsearch is down.')];
        yield 'permanent' => [new SearchIndexError('Mapping rejected.')];
    }

    #[DataProvider('searchFailures')]
    public function testAFailureOfTheIndexIsPassedOn(\Throwable $failure): void
    {
        $this->indexer->willReturn($failure);

        $this->expectExceptionObject($failure);

        $this->indexing()->reindex(self::PRODUCT_ID);
    }

    private function indexing(): ProductIndexing
    {
        return new ProductIndexing($this->source, $this->offers, $this->indexer, new ProductDocumentBuilder(), $this->clock);
    }

    private static function snapshot(): ProductSearchSnapshot
    {
        return new ProductSearchSnapshot(
            self::PRODUCT_ID,
            'Buty biegowe',
            'buty-biegowe',
            'Lekkie',
            self::CATEGORY_ID,
            [],
            [[self::CATEGORY_ID]],
            null,
            ['Buty'],
            [],
            [['id' => self::VARIANT_ID, 'values' => []]],
        );
    }
}
