<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Infrastructure;

use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Application\Search\VariantFacts;
use App\Catalog\Domain\Product\ProductStatus;
use App\Pricing\Domain\Promotion\PromotionPriceChangeSource;
use App\Pricing\Domain\Promotion\PromotionPricesChanged;
use App\Pricing\Infrastructure\Search\ReindexProductOnPromotionChange;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use App\Tests\Support\InMemory\RecordingProductIndexer;
use App\Tests\Unit\Catalog\Search\InMemoryProductSearchSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ReindexProductOnPromotionChangeTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string VARIANT = '0192f0c4-7c1a-7c4e-9a6e-000000000011';
    private const string CATEGORY = '0192f0c4-7c1a-7c4e-9a6e-0000000000c1';

    private InMemoryProductOfferFacts $offers;
    private RecordingProductIndexer $indexer;

    protected function setUp(): void
    {
        $this->offers = new InMemoryProductOfferFacts([self::PRODUCT => new ProductFacts(4_720, [self::VARIANT => new VariantFacts(4_720, true)])]);
        $this->indexer = new RecordingProductIndexer();
    }

    public function testReindexesTheProductWithItsCurrentOffer(): void
    {
        $this->consumer()(new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Promotion, new \DateTimeImmutable()));

        self::assertCount(1, $this->indexer->indexed);
        self::assertSame(self::PRODUCT, $this->indexer->indexed[0]->id);
        self::assertSame(4_720, $this->indexer->indexed[0]->fromGross);
    }

    public function testAProductThatIsNotInTheDatabaseIsHandledWithoutWriting(): void
    {
        $this->consumer()(new PromotionPricesChanged('0192f0c4-7c1a-7c4e-9a6e-0000000000ff', PromotionPriceChangeSource::Promotion, new \DateTimeImmutable()));

        self::assertSame([], $this->indexer->indexed);
    }

    public function testASeedChangeIsHandledWithoutReindexing(): void
    {
        $this->consumer()(new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Seed, new \DateTimeImmutable()));

        self::assertSame([], $this->offers->asked);
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

        $this->consumer()(new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Promotion, new \DateTimeImmutable()));
    }

    public function testAPermanentFailureOfTheIndexIsNotRetried(): void
    {
        $failure = $this->failIndex(new SearchIndexError('Mapping rejected.'));

        try {
            $this->consumer()(new PromotionPricesChanged(self::PRODUCT, PromotionPriceChangeSource::Promotion, new \DateTimeImmutable()));
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

    private function consumer(): ReindexProductOnPromotionChange
    {
        $source = new InMemoryProductSearchSource([
            new ProductSearchSnapshot(self::PRODUCT, 'Kurtka', 'kurtka', '', self::CATEGORY, [], [[self::CATEGORY]], null, ['Odzież'], [], [['id' => self::VARIANT, 'values' => []]], ProductStatus::Published),
        ]);

        return new ReindexProductOnPromotionChange(
            new ProductIndexing($source, $this->offers, $this->indexer, new ProductDocumentBuilder(), new FixedCatalogClock()),
        );
    }
}
