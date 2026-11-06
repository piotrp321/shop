<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
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
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
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
        self::assertSame([['gross' => 7_900, 'purchasable' => true, 'axes' => [], 'options' => []]], $source['variants']);

        $this->connection()->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE variant_id = ?', [$variantId]);
        $this->publisher()->publish(new StockLevelChanged($variantId, AvailabilityLevel::OutOfStock, StockChangeSource::Staff, new \DateTimeImmutable()));
        $this->consumeOutboxAndEvents(2);

        $source = $this->stored($productId);
        self::assertArrayNotHasKey('from_gross', $source);
        self::assertSame([['gross' => 7_900, 'purchasable' => false, 'axes' => [], 'options' => []]], $source['variants']);
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
        new DoctrineProductRepository($entityManager)->save($product);

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
