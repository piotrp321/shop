<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Search\SearchIndexRebuildRunning;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresSearchIndexRebuildLock;
use App\Tests\Factory\CategoryFactory;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * A product created during a rebuild would be a document the rebuild did not count, so its exact check would fail
 * (docs/features/search/spec.md, "Zapisy w trakcie przebudowy"). A creation shares the lock of the rebuild in its
 * transaction: it is refused while a rebuild (another process) holds the lock, and a rebuild cannot start while a
 * creation has not committed.
 */
final class ProductCreationDuringRebuildTest extends KernelTestCase
{
    use Factories;

    private string $alias;
    private Connection $holder;
    private string $categoryId;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $alias = $container->getParameter('app.search.alias');
        self::assertIsString($alias);
        $this->alias = $alias;
        $connection = $container->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        // A plain DBAL configuration, so the DAMA static connection driver is not applied: another process.
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $connection->getParams();
        $this->holder = DriverManager::getConnection($params, new DbalConfiguration());

        $entityManager = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $this->categoryId = $category->id()->toString();
    }

    protected function tearDown(): void
    {
        $this->holder->close();
        parent::tearDown();
    }

    public function testWhileAnotherProcessRebuildsTheIndexAProductIsRefusedAndAfterItIsCreated(): void
    {
        $rebuild = new PostgresSearchIndexRebuildLock($this->holder, $this->alias);
        self::assertTrue($rebuild->tryAcquire());

        try {
            try {
                $this->create('buty-w-trakcie');
                self::fail('Expected SearchIndexRebuildRunning.');
            } catch (HandlerFailedException $failure) {
                $wrapped = $failure->getWrappedExceptions();
                self::assertCount(1, $wrapped);
                self::assertInstanceOf(SearchIndexRebuildRunning::class, reset($wrapped));
            }
        } finally {
            $rebuild->release();
        }

        // Nothing was saved: the same slug is free once the rebuild has ended.
        $this->create('buty-w-trakcie');
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame(1, $connection->fetchOne("SELECT count(*)::int FROM catalog_product WHERE slug = 'buty-w-trakcie'"));
    }

    public function testARebuildCannotStartWhileACreationHasNotCommitted(): void
    {
        $creation = new PostgresSearchIndexRebuildLock($this->holder, $this->alias);
        $rebuild = self::getContainer()->get(SearchIndexRebuildLock::class);
        self::assertInstanceOf(SearchIndexRebuildLock::class, $rebuild);

        $this->holder->beginTransaction();
        self::assertTrue($creation->tryShareInTransaction());
        // Two creations share the lock at once.
        self::assertTrue($creation->tryShareInTransaction());
        try {
            self::assertFalse($rebuild->tryAcquire());
        } finally {
            $this->holder->commit();
        }

        // The share ends with the transaction.
        self::assertTrue($rebuild->tryAcquire());
        $rebuild->release();
    }

    private function create(string $slug): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new CreateProduct(
            Uuid::v7()->toRfc4122(),
            'Buty biegowe',
            $slug,
            'Lekkie',
            '',
            '',
            [],
            $this->categoryId,
            [],
            'standard',
            [],
            [],
            [['id' => Uuid::v7()->toRfc4122(), 'values' => [], 'sku' => '', 'ean' => '']],
            ProductStatus::Draft,
            'staff-sub-1',
        ));
    }
}
