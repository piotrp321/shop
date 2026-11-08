<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\PublishProduct;
use App\Catalog\Application\Search\ProductSearchCriteria;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockLevelChanged;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\VariantPriceChanged;
use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use App\Tests\Support\OriginalImages;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The reindexing of a product after a price or stock level change on real infrastructure: outbox (Postgres) → relay →
 * RabbitMQ → the consumers of Pricing and Inventory → Catalog's ProductIndexing (offer of Cart) → Elasticsearch
 * (a temporary alias). The store's cache is a spy.
 */
final class SearchReindexFlowTest extends MessagingKernelTestCase
{
    use Factories;
    use UsesTemporarySearchAlias;

    protected function setUp(): void
    {
        parent::setUp();
        OriginalImages::exist(self::getContainer(), 'originals/'.str_repeat('a', 64).'.jpg');
        $this->maintenance()->setup();
        self::getContainer()->set(
            ElasticsearchProductIndexer::class,
            new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias()),
        );
        self::getContainer()->set(HttpStorefrontCache::class, new SpyStorefrontCache());
    }

    public function testAPriceChangeAndALevelChangeReindexTheProductWithItsOffer(): void
    {
        [$productId, $variantId] = $this->catalogProduct();
        VariantPriceRecordFactory::store($this->entityManager(), ['variantId' => $variantId, 'gross' => 7_900]);
        StockFixture::stock($this->connection(), 4, variantId: $variantId);

        $this->publisher()->publish(new VariantPriceChanged($variantId, PriceChangeSource::Staff, new \DateTimeImmutable()));
        $this->consumeOutboxAndEvents(2);

        $source = $this->stored($productId);
        self::assertSame(7_900, $source['from_gross']);
        self::assertSame([['gross' => 7_900, 'purchasable' => true, 'options' => [], 'pairs' => []]], $source['variants']);

        $this->connection()->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE variant_id = ?', [$variantId]);
        $this->publisher()->publish(new StockLevelChanged($variantId, AvailabilityLevel::OutOfStock, StockChangeSource::Staff, new \DateTimeImmutable()));
        $this->consumeOutboxAndEvents(2);

        $source = $this->stored($productId);
        self::assertArrayNotHasKey('from_gross', $source);
        self::assertSame([['gross' => 7_900, 'purchasable' => false, 'options' => [], 'pairs' => []]], $source['variants']);
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * A product created in the panel is a draft: ProductCreated writes its draft document, which the store does not
     * find; its publication (ProductChanged) writes the published document, which the store finds.
     */
    public function testADraftIsIndexedAsADraftAndItsPublicationMakesItFound(): void
    {
        $categoryId = (string) Uuid::v7();
        $productId = (string) Uuid::v7();
        $this->commandBus()->dispatch(new CreateCategory($categoryId, 'Kurtki', 'kurtki-'.substr($categoryId, -12), null));
        $this->commandBus()->dispatch(new CreateProduct(
            $productId,
            'Kurtka puchowa',
            'kurtka-puchowa-'.substr($productId, -12),
            '',
            $categoryId,
            [],
            'standard',
            [['key' => 'originals/'.str_repeat('a', 64).'.jpg', 'alt' => 'front']],
            [],
            [['id' => (string) Uuid::v7(), 'values' => []]],
            ProductStatus::Draft,
            'staff-sub-1',
        ));
        $this->consumeOutboxAndEvents(2);

        self::assertSame('draft', $this->stored($productId)['status']);
        self::assertSame(0, $this->found()->total);

        $this->commandBus()->dispatch(new PublishProduct($productId, 1, 'staff-sub-1'));
        $this->consumeOutboxAndEvents(2);

        self::assertSame('published', $this->stored($productId)['status']);
        $found = $this->found();
        self::assertSame(1, $found->total);
        self::assertSame($productId, $found->items[0]->id);
        self::assertSame(0, $this->rows('failed'));
    }

    public function testSeedEventsAndUnknownVariantsAreAckedWithoutReindexing(): void
    {
        [, $variantId] = $this->catalogProduct();
        $unknown = (string) Uuid::v4();
        $this->publisher()->publish(
            new VariantPriceChanged($variantId, PriceChangeSource::Seed, new \DateTimeImmutable()),
            new StockLevelChanged($variantId, AvailabilityLevel::InStock, StockChangeSource::Seed, new \DateTimeImmutable()),
            new VariantPriceChanged($unknown, PriceChangeSource::Staff, new \DateTimeImmutable()),
            new StockLevelChanged($unknown, AvailabilityLevel::InStock, StockChangeSource::Staff, new \DateTimeImmutable()),
        );

        $this->consumeOutboxAndEvents(8);

        self::esClient()->indices()->refresh(['index' => $this->alias()]);
        $count = self::esClient()->count(['index' => $this->alias()]);
        \assert($count instanceof Elasticsearch);
        self::assertSame(0, $count['count']);
        self::assertSame(0, $this->eventsInQueue());
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * @return array{string, string} the product id and the id of its only variant
     */
    private function catalogProduct(): array
    {
        $entityManager = $this->entityManager();
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $variantId = (string) Uuid::v4();
        $product = ProductFactory::createOne(['mainCategory' => $category, 'variants' => [['id' => $variantId, 'values' => []]]]);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());

        return [$product->id()->toString(), $variantId];
    }

    /**
     * @return array<mixed>
     */
    private function stored(string $productId): array
    {
        $document = self::esClient()->get(['index' => $this->alias(), 'id' => $productId]);
        \assert($document instanceof Elasticsearch);
        $source = $document['_source'];
        self::assertIsArray($source);

        return $source;
    }

    private function found(): SearchResult
    {
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        return new ElasticsearchProductSearch(self::clientFactory()->create(2.0), $this->alias())->search(new ProductSearchCriteria(1, 24));
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function consumeOutboxAndEvents(int $messages): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => $messages, '--time-limit' => 10]);
        $tester->assertCommandIsSuccessful();
    }
}
