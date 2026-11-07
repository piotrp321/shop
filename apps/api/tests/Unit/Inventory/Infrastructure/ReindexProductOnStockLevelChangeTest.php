<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure;

use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Application\Search\VariantFacts;
use App\Catalog\Domain\Product\ProductStatus;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockLevelChanged;
use App\Inventory\Infrastructure\Search\ReindexProductOnStockLevelChange;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryCatalogVariants;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use App\Tests\Support\InMemory\RecordingProductIndexer;
use App\Tests\Unit\Catalog\Search\InMemoryProductSearchSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ReindexProductOnStockLevelChangeTest extends TestCase
{
    private const string VARIANT = '0192f0c4-7c1a-7c4e-9a6e-000000000011';
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string VARIANT_OF_A_MISSING_PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000022';
    private const string CATEGORY = '0192f0c4-7c1a-7c4e-9a6e-0000000000c1';

    private InMemoryCatalogVariants $catalog;
    private InMemoryProductOfferFacts $offers;
    private RecordingProductIndexer $indexer;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryCatalogVariants([
            self::VARIANT => self::PRODUCT,
            self::VARIANT_OF_A_MISSING_PRODUCT => '0192f0c4-7c1a-7c4e-9a6e-0000000000ff',
        ]);
        $this->offers = new InMemoryProductOfferFacts([self::PRODUCT => new ProductFacts(5_900, [self::VARIANT => new VariantFacts(5_900, true)])]);
        $this->indexer = new RecordingProductIndexer();
    }

    /**
     * @return iterable<string, array{StockChangeSource}>
     */
    public static function reindexingSources(): iterable
    {
        yield 'staff' => [StockChangeSource::Staff];
        yield 'system' => [StockChangeSource::System];
    }

    #[DataProvider('reindexingSources')]
    public function testReindexesTheProductOfTheVariantWithItsNewOffer(StockChangeSource $source): void
    {
        $this->consumer()(new StockLevelChanged(self::VARIANT, AvailabilityLevel::LowStock, $source, new \DateTimeImmutable()));

        self::assertSame([self::VARIANT], $this->catalog->asked);
        self::assertCount(1, $this->indexer->indexed);
        self::assertSame(self::PRODUCT, $this->indexer->indexed[0]->id);
        self::assertSame(5_900, $this->indexer->indexed[0]->fromGross);
    }

    public function testAVariantUnknownToCatalogIsHandledWithoutReindexing(): void
    {
        $this->consumer()(new StockLevelChanged('0192f0c4-7c1a-7c4e-9a6e-0000000000ee', AvailabilityLevel::LowStock, StockChangeSource::Staff, new \DateTimeImmutable()));

        self::assertSame([], $this->offers->asked);
        self::assertSame([], $this->indexer->indexed);
    }

    public function testAProductThatIsNotInTheDatabaseIsHandledWithoutWriting(): void
    {
        $this->consumer()(new StockLevelChanged(self::VARIANT_OF_A_MISSING_PRODUCT, AvailabilityLevel::LowStock, StockChangeSource::Staff, new \DateTimeImmutable()));

        self::assertSame([], $this->indexer->indexed);
    }

    public function testASeedChangeIsHandledWithoutAskingCatalogOrReindexing(): void
    {
        $this->consumer()(new StockLevelChanged(self::VARIANT, AvailabilityLevel::LowStock, StockChangeSource::Seed, new \DateTimeImmutable()));

        self::assertSame([], $this->catalog->asked);
        self::assertSame([], $this->indexer->indexed);
    }

    /**
     * @return iterable<string, array{\Closure(self): \Throwable}>
     */
    public static function transientFailures(): iterable
    {
        yield 'search index unavailable' => [static fn (self $test): \Throwable => $test->failIndex(new SearchUnavailable('Elasticsearch is down.'))];
        yield 'offer unavailable' => [static fn (self $test): \Throwable => $test->failOffers(new OfferFactsUnavailable('Connection lost.'))];
    }

    /**
     * @param \Closure(self): \Throwable $breakIt
     */
    #[DataProvider('transientFailures')]
    public function testATransientFailureIsRethrownForTheRetries(\Closure $breakIt): void
    {
        $failure = $breakIt($this);

        $this->expectExceptionObject($failure);

        $this->consumer()(new StockLevelChanged(self::VARIANT, AvailabilityLevel::LowStock, StockChangeSource::Staff, new \DateTimeImmutable()));
    }

    public function testAPermanentFailureOfTheIndexIsNotRetried(): void
    {
        $failure = $this->failIndex(new SearchIndexError('Mapping rejected.'));

        try {
            $this->consumer()(new StockLevelChanged(self::VARIANT, AvailabilityLevel::LowStock, StockChangeSource::Staff, new \DateTimeImmutable()));
            self::fail('The permanent failure was swallowed.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function failIndex(\Throwable $failure): \Throwable
    {
        $this->indexer->willReturn($failure);

        return $failure;
    }

    public function failOffers(\Throwable $failure): \Throwable
    {
        $this->offers->failure = $failure;

        return $failure;
    }

    private function consumer(): ReindexProductOnStockLevelChange
    {
        $source = new InMemoryProductSearchSource([
            new ProductSearchSnapshot(self::PRODUCT, 'Kurtka', 'kurtka', '', self::CATEGORY, [], [[self::CATEGORY]], null, ['Odzież'], [], [['id' => self::VARIANT, 'values' => []]], ProductStatus::Published),
        ]);

        return new ReindexProductOnStockLevelChange(
            $this->catalog->queryBus(),
            new ProductIndexing($source, $this->offers, $this->indexer, new ProductDocumentBuilder(), new FixedCatalogClock()),
        );
    }
}
