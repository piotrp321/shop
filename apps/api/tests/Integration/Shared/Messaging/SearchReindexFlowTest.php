<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\PublishProduct;
use App\Catalog\Application\Search\ActivationResult;
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
use App\Tests\Integration\Catalog\Search\ScriptedBulkFaultsHttpClient;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use App\Tests\Support\OriginalImages;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
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

    /** The transport of the single writes: passes everything on until a test scripts a fault. */
    private ScriptedBulkFaultsHttpClient $bulks;

    protected function setUp(): void
    {
        parent::setUp();
        OriginalImages::exist(self::getContainer(), 'originals/'.str_repeat('a', 64).'.jpg');
        $this->maintenance()->setup();
        $this->bulks = new ScriptedBulkFaultsHttpClient(self::clientFactory()->httpClient(2.0), []);
        self::getContainer()->set(
            ElasticsearchProductIndexer::class,
            new ElasticsearchProductIndexer(self::clientFactory()->createWith($this->bulks), $this->alias()),
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
        self::assertSame([['gross' => 7_900, 'purchasable' => true, 'options' => [], 'axes' => [], 'pairs' => []]], $source['variants']);

        $this->connection()->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE variant_id = ?', [$variantId]);
        $this->publisher()->publish(new StockLevelChanged($variantId, AvailabilityLevel::OutOfStock, StockChangeSource::Staff, new \DateTimeImmutable()));
        $this->consumeOutboxAndEvents(2);

        $source = $this->stored($productId);
        self::assertArrayNotHasKey('from_gross', $source);
        self::assertSame([['gross' => 7_900, 'purchasable' => false, 'options' => [], 'axes' => [], 'pairs' => []]], $source['variants']);
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
        $this->commandBus()->dispatch(CreateCategory::bySeed($categoryId, 'Kurtki', 'kurtki-'.substr($categoryId, -12), null));
        $this->commandBus()->dispatch(new CreateProduct(
            $productId,
            'Kurtka puchowa',
            'kurtka-puchowa-'.substr($productId, -12),
            '',
            '',
            '',
            [],
            $categoryId,
            [],
            'standard',
            [['key' => 'originals/'.str_repeat('a', 64).'.jpg', 'alt' => 'front']],
            [],
            [['id' => (string) Uuid::v7(), 'values' => [], 'sku' => '', 'ean' => '']],
            ProductStatus::Draft,
            'staff-sub-1',
        ));
        // The ProductCreated, relayed and handled.
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

    /**
     * A price change handled while a rebuild switches the alias: the aliases of its _bulk are resolved before the
     * switch, and the previous index is deleted before the item of the alias reaches it (404). The item of the rebuild
     * alias wrote the new index; the message is retried, writes again through the alias and never goes to `failed`.
     */
    public function testAWriteToAnIndexDeletedByTheAliasSwitchIsRetriedAndNotFailed(): void
    {
        $logs = $this->messengerLogs();
        [$productId, $variantId] = $this->catalogProduct();
        VariantPriceRecordFactory::store($this->entityManager(), ['variantId' => $variantId, 'gross' => 5_900]);
        StockFixture::stock($this->connection(), 3, variantId: $variantId);
        $previous = $this->maintenance()->activeIndex();
        $new = $this->maintenance()->createIndex();
        $http = $this->bulks;
        $http->script = [['gone' => [1 => $previous]]];
        $http->afterForward = function () use ($new, $previous): void {
            self::assertSame(ActivationResult::Activated, $this->maintenance()->activate($new, $previous));
            $this->maintenance()->deleteIndex($previous);
        };

        $this->publisher()->publish(new VariantPriceChanged($variantId, PriceChangeSource::Staff, new \DateTimeImmutable()));
        $this->consumeOutboxAndEvents(2);
        $this->awaitEventsInQueue(1, 5.0);
        $this->consume('events');

        self::assertSame(1, $this->countRetries($logs));
        self::assertSame(2, $http->bulkRequests);
        self::assertSame([$new], $this->aliasTargets());
        self::assertSame(5_900, $this->stored($productId)['from_gross']);
        self::assertSame(0, $this->rows('failed'));
        self::assertSame(0, $this->eventsInQueue());
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

    private function messengerLogs(): TestHandler
    {
        $logger = self::getContainer()->get('monolog.logger.messenger');
        self::assertInstanceOf(Logger::class, $logger);
        $logs = new TestHandler();
        $logger->pushHandler($logs);

        return $logs;
    }

    private function countRetries(TestHandler $logs): int
    {
        $count = 0;
        foreach ($logs->getRecords() as $record) {
            if (str_contains($record->message, 'Sending for retry')) {
                ++$count;
            }
        }

        return $count;
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
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => $messages, '--time-limit' => 10, '--sleep' => 0.05]);
        $tester->assertCommandIsSuccessful();
    }
}
