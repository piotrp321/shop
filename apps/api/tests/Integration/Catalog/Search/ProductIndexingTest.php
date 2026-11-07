<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Cart\Infrastructure\Catalog\QueryBusProductOfferFacts;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\ReindexOutcome;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\InMemory\FixedCatalogClock;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * ProductIndexing on the real database (shop_test, rolled back), the real offer of Cart and a temporary alias of the
 * real Elasticsearch.
 */
final class ProductIndexingTest extends KernelTestCase
{
    use Factories;
    use UsesTemporarySearchAlias;

    private FixedCatalogClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedCatalogClock(new \DateTimeImmutable('2026-11-06T12:00:00.000002+00:00'));
        $this->maintenance()->setup();
    }

    public function testWritesTheProductWithItsOfferAndRejectsAnOlderReadWrittenAfterANewerOne(): void
    {
        $productId = $this->productPricedAt(4_900, 'Pierwsza');

        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));
        $this->rename($productId, 'Druga');
        // A read that started a microsecond earlier, written after the newer one: rejected.
        $this->clock->advance('-1 microsecond');
        self::assertSame(ReindexOutcome::Stale, $this->indexing()->reindex($productId));

        $source = $this->stored($productId);
        self::assertSame('Pierwsza', $source['name']);
        self::assertSame(1_793_966_400_000_002, $source['version']);
        self::assertSame(4_900, $source['from_gross']);
        self::assertSame([['gross' => 4_900, 'purchasable' => true, 'options' => [], 'pairs' => []]], $source['variants']);

        // A read that started at the same moment or later is written.
        $this->clock->advance('+1 microsecond');
        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));
        self::assertSame('Druga', $this->stored($productId)['name']);
    }

    public function testADraftIsWrittenAsADraftDocumentThatTheStoreDoesNotFind(): void
    {
        $productId = $this->productPricedAt(4_900, 'Kurtka szkic', ProductStatus::Draft);

        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));

        self::assertSame('draft', $this->stored($productId)['status']);
        self::assertSame([], $this->found());
    }

    /**
     * Unpublishing, then a late handling that read the product while it was still published: the older write is
     * stale, and the draft document stays (the tombstone keeps its version, no matter how late the write comes).
     */
    public function testALateOlderPublishedWriteAfterANewerDraftWriteIsStale(): void
    {
        $productId = $this->productPricedAt(4_900, 'Kurtka', ProductStatus::Published);
        $this->changeStatusTo($productId, ProductStatus::Draft);
        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));
        $draft = $this->stored($productId);

        $this->changeStatusTo($productId, ProductStatus::Published);
        $this->clock->advance('-1 microsecond');
        self::assertSame(ReindexOutcome::Stale, $this->indexing()->reindex($productId));

        self::assertSame($draft, $this->stored($productId));
        self::assertSame('draft', $draft['status']);
        $result = $this->search();
        self::assertSame(0, $result->total);
        self::assertSame([], $result->facets->options);
        self::assertSame(0, $result->facets->availableCount);
        self::assertNull($result->facets->price);
    }

    /**
     * Publishing after an unpublish, with the handling of the unpublish coming late: the published document stays.
     */
    public function testALateOlderDraftWriteAfterANewerPublishedWriteIsStale(): void
    {
        $productId = $this->productPricedAt(4_900, 'Kurtka', ProductStatus::Published);
        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));

        $this->changeStatusTo($productId, ProductStatus::Draft);
        $this->clock->advance('-1 microsecond');
        self::assertSame(ReindexOutcome::Stale, $this->indexing()->reindex($productId));

        self::assertSame('published', $this->stored($productId)['status']);
        self::assertSame([$productId], $this->found());
    }

    public function testARepeatedWriteOfTheSameVersionIsAcceptedWithoutChangingTheDocument(): void
    {
        $productId = $this->productPricedAt(4_900, 'Kurtka szkic', ProductStatus::Draft);
        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));
        $first = $this->stored($productId);

        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));

        self::assertSame($first, $this->stored($productId));
        self::assertSame([], $this->found());
    }

    public function testThePublicationOfADraftMakesItFound(): void
    {
        $productId = $this->productPricedAt(4_900, 'Kurtka szkic', ProductStatus::Draft);
        $this->indexing()->reindex($productId);

        $this->changeStatusTo($productId, ProductStatus::Published);
        $this->clock->advance('+1 second');
        self::assertSame(ReindexOutcome::Indexed, $this->indexing()->reindex($productId));

        self::assertSame([$productId], $this->found());
        self::assertSame(1, $this->search()->facets->availableCount);
    }

    public function testAProductThatIsNotInTheDatabaseIsProductNotFound(): void
    {
        self::assertSame(ReindexOutcome::ProductNotFound, $this->indexing()->reindex((string) Uuid::v4()));
    }

    private function indexing(): ProductIndexing
    {
        $container = self::getContainer();
        $source = $container->get(ProductSearchSource::class);
        self::assertInstanceOf(ProductSearchSource::class, $source);
        $queryBus = $container->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);

        return new ProductIndexing(
            $source,
            new QueryBusProductOfferFacts($queryBus),
            new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias()),
            new ProductDocumentBuilder(),
            $this->clock,
        );
    }

    private function productPricedAt(int $gross, string $name, ProductStatus $status = ProductStatus::Published): string
    {
        $entityManager = $this->entityManager();
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $variantId = (string) Uuid::v4();
        $product = ProductFactory::createOne(['mainCategory' => $category, 'name' => $name, 'variants' => [['id' => $variantId, 'values' => []]], 'status' => $status]);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());
        VariantPriceRecordFactory::store($entityManager, ['variantId' => $variantId, 'gross' => $gross]);
        StockFixture::stock($entityManager->getConnection(), 3, variantId: $variantId);

        return $product->id()->toString();
    }

    private function changeStatusTo(string $productId, ProductStatus $status): void
    {
        $this->entityManager()->getConnection()->executeStatement('UPDATE catalog_product SET status = ? WHERE id = ?', [$status->value, $productId]);
    }

    /**
     * @return list<string> the ids of the products the store finds
     */
    private function found(): array
    {
        return array_map(static fn (ProductDocument $document): string => $document->id, $this->search()->items);
    }

    private function search(): SearchResult
    {
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        return new ElasticsearchProductSearch(self::clientFactory()->create(2.0), $this->alias())->search(new ProductSearchCriteria(1, 24));
    }

    private function rename(string $productId, string $name): void
    {
        $this->entityManager()->getConnection()->executeStatement('UPDATE catalog_product SET name = ? WHERE id = ?', [$name, $productId]);
    }

    /**
     * @return array<mixed>
     */
    private function stored(string $productId): array
    {
        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => $productId]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        self::assertIsArray($source);

        return $source;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
