<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Demo;

use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Application\Port\ProductIndexer;
use App\Catalog\Application\Port\SearchIndexMaintenance;
use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use App\Catalog\Infrastructure\Demo\DbalCatalogReset;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * CatalogReset on the real services: shop_test (rolled back after the test), the test bucket and test_products.
 */
final class DbalCatalogResetTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;
    private FilesystemOperator $filesystem;
    private ProductImageStorage $storage;
    private ProductIndexer $indexer;
    private ProductSearchIndexRebuilder $rebuilder;
    private SearchIndexMaintenance $maintenance;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::assertSame('product-images-test', $container->getParameter('app.images_bucket'));
        self::assertSame('test_products', $container->getParameter('app.search.alias'));

        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $filesystem = $container->get('test.product_images.storage');
        self::assertInstanceOf(FilesystemOperator::class, $filesystem);
        $this->filesystem = $filesystem;
        $storage = $container->get(ProductImageStorage::class);
        self::assertInstanceOf(ProductImageStorage::class, $storage);
        $this->storage = $storage;
        $indexer = $container->get(\App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer::class);
        self::assertInstanceOf(ProductIndexer::class, $indexer);
        $this->indexer = $indexer;
        $rebuilder = $container->get('test.'.ProductSearchIndexRebuilder::class);
        self::assertInstanceOf(ProductSearchIndexRebuilder::class, $rebuilder);
        $this->rebuilder = $rebuilder;
        $maintenance = $container->get(SearchIndexMaintenance::class);
        self::assertInstanceOf(SearchIndexMaintenance::class, $maintenance);
        $this->maintenance = $maintenance;

        $this->maintenance->recreate();
        $this->storage->purge();
    }

    protected function tearDown(): void
    {
        $this->maintenance->recreate();
        $this->storage->purge();
        parent::tearDown();
    }

    public function testRemovesCatalogRowsOutboxEventsStoredImagesAndIndexedDocuments(): void
    {
        $this->saveProductWithAdditionalCategory();
        $this->storage->store((string) file_get_contents(__DIR__.'/../../../Fixtures/images/sample.jpg'));
        $this->insertMessage('outbox');
        $this->insertMessage('failed');
        self::assertSame(1, $this->rebuilder->rebuild(10)->documents);
        self::assertSame(1, $this->indexer->countDocuments());

        $this->reset()->reset();

        foreach (['catalog_product_category', 'catalog_product', 'catalog_category'] as $table) {
            self::assertSame(0, $this->rowCount($table), $table);
        }
        self::assertSame(0, $this->rowCount('messenger_messages', 'outbox'));
        // Events that already failed are left for inspection.
        self::assertSame(1, $this->rowCount('messenger_messages', 'failed'));
        self::assertSame([], $this->filesystem->listContents('', true)->toArray());
        self::assertSame(0, $this->indexer->countDocuments());
    }

    public function testResetOfAnEmptyCatalogSucceeds(): void
    {
        $this->reset()->reset();

        self::assertSame(0, $this->rowCount('catalog_product'));
        self::assertSame(0, $this->indexer->countDocuments());
    }

    private function reset(): DbalCatalogReset
    {
        return new DbalCatalogReset($this->connection, $this->storage, $this->rebuilder);
    }

    private function saveProductWithAdditionalCategory(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $main = CategoryFactory::createOne();
        $extra = CategoryFactory::createOne();
        $categories = new DoctrineCategoryRepository($entityManager);
        $categories->save($main);
        $categories->save($extra);
        new DoctrineProductRepository($entityManager)->save(
            ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$extra]]),
        );
        $entityManager->flush();
    }

    private function insertMessage(string $queue): void
    {
        $this->connection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES (:body, :headers, :queue, NOW(), NOW())',
            ['body' => '{}', 'headers' => '{}', 'queue' => $queue],
        );
    }

    private function rowCount(string $table, ?string $queue = null): int
    {
        $count = null === $queue
            ? $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table)
            : $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE queue_name = ?', [$queue]);
        \assert(\is_int($count));

        return $count;
    }
}
