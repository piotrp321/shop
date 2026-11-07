<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Cart\Infrastructure\Catalog\QueryBusProductOfferFacts;
use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresSearchIndexRebuildLock;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Catalog\Infrastructure\Search\ElasticsearchSearchIndexMaintenance;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use App\Catalog\Infrastructure\SystemClock;
use App\Catalog\UI\Console\ReindexCommand;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;
use App\Tests\Support\Search\ResponseDroppingHttpClient;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * catalog:reindex on the real test_products alias, with products in shop_test (rolled back after each test).
 */
final class SearchIndexRebuildTest extends KernelTestCase
{
    use Factories;

    private SearchIndexMaintenance $maintenance;
    private Connection $connection;
    private string $alias;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $maintenance = $container->get(SearchIndexMaintenance::class);
        self::assertInstanceOf(SearchIndexMaintenance::class, $maintenance);
        $this->maintenance = $maintenance;
        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $alias = $container->getParameter('app.search.alias');
        self::assertSame('test_products', $alias);
        $this->alias = $alias;

        // Every test starts from (and leaves behind) a fresh alias on an empty index.
        $this->maintenance->recreate();
    }

    protected function tearDown(): void
    {
        $this->maintenance->recreate();
        parent::tearDown();
    }

    public function testRebuildsInBatchesAndSwitchesTheAlias(): void
    {
        $ids = $this->createProducts(7);
        $previous = $this->maintenance->activeIndex();
        $before = ProductIndexing::version(new \DateTimeImmutable());

        $tester = $this->reindexCommand();
        self::assertSame(Command::SUCCESS, $tester->execute(['--batch-size' => '3']), $tester->getDisplay());

        $current = $this->maintenance->activeIndex();
        self::assertNotSame($previous, $current);
        self::assertFalse($this->indexExists($previous), 'The previous index was not deleted.');
        $versions = $this->indexedVersions();
        self::assertSame($ids, array_keys($versions));
        // One version per batch: the time taken before its reads.
        self::assertCount(3, array_unique($versions));
        foreach ($versions as $version) {
            self::assertGreaterThanOrEqual($before, $version);
        }
        self::assertStringContainsString($current, $tester->getDisplay());
    }

    public function testTheRebuiltDocumentsCarryTheOfferFacts(): void
    {
        $this->createProducts(2);
        $entityManager = $this->entityManager();
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $variantId = (string) Uuid::v4();
        $product = ProductFactory::createOne(['mainCategory' => $category, 'variants' => [['id' => $variantId, 'values' => []]]]);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());
        VariantPriceRecordFactory::store($entityManager, ['variantId' => $variantId, 'gross' => 12_900]);
        StockFixture::stock($entityManager->getConnection(), 2, variantId: $variantId);

        self::assertSame(Command::SUCCESS, $this->reindexCommand()->execute([]));

        $source = $this->indexedSource($product->id()->toString());
        self::assertSame(12_900, $source['from_gross']);
        self::assertSame([['gross' => 12_900, 'purchasable' => true, 'options' => [], 'pairs' => []]], $source['variants']);
    }

    public function testTheRebuildIndexesDraftsAsDraftDocuments(): void
    {
        $entityManager = $this->entityManager();
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $draft = ProductFactory::createOne(['mainCategory' => $category, 'status' => ProductStatus::Draft]);
        $published = ProductFactory::createOne(['mainCategory' => $category]);
        new DoctrineProductRepository($entityManager)->save($draft, null, null, new \DateTimeImmutable());
        new DoctrineProductRepository($entityManager)->save($published, null, null, new \DateTimeImmutable());

        self::assertSame(Command::SUCCESS, $this->reindexCommand()->execute([]));

        self::assertSame('draft', $this->indexedSource($draft->id()->toString())['status']);
        self::assertSame('published', $this->indexedSource($published->id()->toString())['status']);
    }

    public function testAFailureOfTheOfferFactsStopsTheRebuildAndKeepsTheAlias(): void
    {
        $this->createProducts(2);
        $previous = $this->maintenance->activeIndex();
        $indexesBefore = $this->aliasIndexes();
        $offers = new InMemoryProductOfferFacts();
        $offers->failure = new OfferFactsUnavailable('Reading the product offers failed: connection lost.');
        self::getContainer()->set(QueryBusProductOfferFacts::class, $offers);

        $tester = $this->reindexCommand();
        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString('connection lost', $tester->getDisplay());
        self::assertSame($previous, $this->maintenance->activeIndex());
        self::assertSame($indexesBefore, $this->aliasIndexes());
    }

    public function testRebuildWhileAnotherProcessHoldsTheLockExitsWithOneAndChangesNothing(): void
    {
        $this->createProducts(2);
        $previous = $this->maintenance->activeIndex();
        $indexesBefore = $this->aliasIndexes();
        $holder = $this->independentConnection();
        self::assertTrue(new PostgresSearchIndexRebuildLock($holder, $this->alias)->tryAcquire());

        try {
            $tester = $this->reindexCommand();
            self::assertSame(Command::FAILURE, $tester->execute([]));
        } finally {
            $holder->close();
        }

        self::assertSame($previous, $this->maintenance->activeIndex());
        self::assertSame($indexesBefore, $this->aliasIndexes());
        self::assertSame([], $this->indexedVersions());
    }

    public function testUnknownAliasStateExitsWithTwoNamesBothIndexesAndDeletesNothing(): void
    {
        $this->createProducts(2);
        $previous = $this->maintenance->activeIndex();
        $factory = $this->clientFactory();
        // The alias switch is executed, its response is lost and the cluster stays unreachable afterwards.
        $http = ResponseDroppingHttpClient::aliasSwitches($factory->httpClient(2.0), dropEverythingAfter: true);
        $maintenance = new ElasticsearchSearchIndexMaintenance($factory->createWith($http), $factory->create(30.0), new ProductIndexDefinition(1, 0), $this->alias);
        $lock = self::getContainer()->get(SearchIndexRebuildLock::class);
        self::assertInstanceOf(SearchIndexRebuildLock::class, $lock);
        $source = self::getContainer()->get(ProductSearchSource::class);
        self::assertInstanceOf(ProductSearchSource::class, $source);
        $application = new Application();
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $application->addCommand(new ReindexCommand(new ProductSearchIndexRebuilder($source, $maintenance, $lock, new ProductDocumentBuilder(), new QueryBusProductOfferFacts($queryBus), new SystemClock()), 500));
        $tester = new CommandTester($application->find('catalog:reindex'));

        self::assertSame(2, $tester->execute([]));

        $current = $this->maintenance->activeIndex();
        self::assertNotSame($previous, $current);
        self::assertTrue($this->indexExists($previous), 'An index the alias might point to was deleted.');
        self::assertStringContainsString($previous, $tester->getDisplay());
        self::assertStringContainsString($current, $tester->getDisplay());
        // The lock is free again: the next rebuild runs.
        self::assertSame(Command::SUCCESS, $this->reindexCommand()->execute([]));
    }

    public function testInvalidBatchSizeExitsWithOne(): void
    {
        $previous = $this->maintenance->activeIndex();

        self::assertSame(Command::FAILURE, $this->reindexCommand()->execute(['--batch-size' => '0']));

        self::assertSame($previous, $this->maintenance->activeIndex());
    }

    public function testResetSwitchesTheAliasToAnEmptyIndex(): void
    {
        $this->createProducts(2);
        self::assertSame(Command::SUCCESS, $this->reindexCommand()->execute([]));
        $previous = $this->maintenance->activeIndex();
        $rebuilder = self::getContainer()->get(ProductSearchIndexRebuilder::class);
        self::assertInstanceOf(ProductSearchIndexRebuilder::class, $rebuilder);

        $rebuilder->reset();

        self::assertNotSame($previous, $this->maintenance->activeIndex());
        self::assertFalse($this->indexExists($previous));
        self::assertSame([], $this->indexedVersions());
    }

    /**
     * @return list<string> the product ids, sorted
     */
    private function createProducts(int $count): array
    {
        $entityManager = $this->entityManager();
        $categories = new DoctrineCategoryRepository($entityManager);
        $products = new DoctrineProductRepository($entityManager);
        $category = CategoryFactory::createOne();
        $categories->save($category);

        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $product = ProductFactory::createOne(['mainCategory' => $category]);
            $products->save($product, null, null, new \DateTimeImmutable());
            $ids[] = $product->id()->toString();
        }
        sort($ids);

        return $ids;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @return array<mixed>
     */
    private function indexedSource(string $id): array
    {
        $response = $this->client()->get(['index' => $this->alias, 'id' => $id]);
        \assert($response instanceof Elasticsearch);
        $source = $response['_source'];
        self::assertIsArray($source);

        return $source;
    }

    /**
     * @return array<string, int> document id => version (both the document field and the Elasticsearch version)
     */
    private function indexedVersions(): array
    {
        $client = $this->client();
        $client->indices()->refresh(['index' => $this->alias]);
        $response = $client->search(['index' => $this->alias, 'body' => ['size' => 100, 'version' => true, 'sort' => [['id' => 'asc']]]]);
        \assert($response instanceof Elasticsearch);
        $result = $response->asArray()['hits'] ?? null;
        \assert(\is_array($result) && \is_array($result['hits']));
        $hits = $result['hits'];

        $versions = [];
        foreach ($hits as $hit) {
            \assert(\is_array($hit) && \is_string($hit['_id']) && \is_array($hit['_source']));
            self::assertSame($hit['_version'], $hit['_source']['version']);
            \assert(\is_int($hit['_version']));
            $versions[$hit['_id']] = $hit['_version'];
        }

        return $versions;
    }

    /**
     * @return list<string>
     */
    private function aliasIndexes(): array
    {
        $response = $this->client()->cat()->indices(['index' => $this->alias.'_*', 'h' => 'index', 'format' => 'json']);
        \assert($response instanceof Elasticsearch);
        $names = array_map(static fn (mixed $row): string => \is_array($row) && \is_string($row['index']) ? $row['index'] : '', $response->asArray());
        sort($names);

        return $names;
    }

    private function indexExists(string $index): bool
    {
        $response = $this->client()->indices()->exists(['index' => $index]);
        \assert($response instanceof Elasticsearch);

        return $response->asBool();
    }

    private function reindexCommand(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester(new FrameworkApplication(self::$kernel)->find('catalog:reindex'));
    }

    private function independentConnection(): Connection
    {
        // A plain DBAL configuration, so the DAMA static connection driver is not applied.
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();

        return DriverManager::getConnection($params, new DbalConfiguration());
    }

    private function clientFactory(): ElasticsearchClientFactory
    {
        $factory = self::getContainer()->get(ElasticsearchClientFactory::class);
        self::assertInstanceOf(ElasticsearchClientFactory::class, $factory);

        return $factory;
    }

    private function client(): Client
    {
        return $this->clientFactory()->create(30.0);
    }
}
