<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresSearchIndexRebuildLock;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Catalog\Infrastructure\Search\ElasticsearchSearchIndexMaintenance;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use App\Catalog\UI\Console\ReindexCommand;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
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
        $versions = $this->createProducts(7);
        $previous = $this->maintenance->activeIndex();

        $tester = $this->reindexCommand();
        self::assertSame(Command::SUCCESS, $tester->execute(['--batch-size' => '3']), $tester->getDisplay());

        $current = $this->maintenance->activeIndex();
        self::assertNotSame($previous, $current);
        self::assertFalse($this->indexExists($previous), 'The previous index was not deleted.');
        self::assertSame($versions, $this->indexedVersions());
        self::assertStringContainsString($current, $tester->getDisplay());
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
        $application->addCommand(new ReindexCommand(new ProductSearchIndexRebuilder($source, $maintenance, $lock, new ProductDocumentBuilder()), 500));
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
     * @return array<string, int> product id => version, sorted by id
     */
    private function createProducts(int $count): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $categories = new DoctrineCategoryRepository($entityManager);
        $products = new DoctrineProductRepository($entityManager);
        $category = CategoryFactory::createOne();
        $categories->save($category);

        $versions = [];
        for ($i = 0; $i < $count; ++$i) {
            $product = ProductFactory::createOne(['mainCategory' => $category]);
            $products->save($product);
            $versions[$product->id()->toString()] = 1;
        }
        // Distinct versions, so the test sees that each document carries its product's version.
        foreach (array_keys($versions) as $position => $id) {
            $versions[$id] = $position + 1;
            $this->connection->executeStatement('UPDATE catalog_product SET version = ? WHERE id = ?', [$position + 1, $id]);
        }
        ksort($versions);

        return $versions;
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
