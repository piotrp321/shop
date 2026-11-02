<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Query\ListBackofficeStock;
use App\Inventory\Application\Query\StockList;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * ListBackofficeStock on PostgreSQL, through the real query bus and Catalog (shop_test, rolled back after each test).
 */
final class ListBackofficeStockTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private StatementCounter $counter;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $this->counter = $counter;
    }

    public function testTheStocksOfAPageAreReadWithOneStatementWhateverTheSize(): void
    {
        $this->products(25);
        $this->ask(new ListBackofficeStock(null, null, null, 1)); // warms up what is read once per process

        $before = $this->counter->count();
        $one = $this->ask(new ListBackofficeStock(null, null, null, 1));
        $statementsForOne = $this->counter->count() - $before;

        $before = $this->counter->count();
        $page = $this->ask(new ListBackofficeStock(null, null, null, 25));
        $statementsForPage = $this->counter->count() - $before;

        self::assertCount(1, $one->items);
        self::assertCount(25, $page->items);
        self::assertSame($statementsForOne, $statementsForPage);
    }

    public function testListsTheStoredStockAndNullForAProductWithoutOne(): void
    {
        $ids = $this->products(2);
        StockFixture::stock($this->connection, 10, 4, 3, $ids[0]);

        $list = $this->ask(new ListBackofficeStock(null, null, null, 25));

        self::assertSame($ids, array_map(static fn ($item): string => $item->productId, $list->items));
        self::assertNull($list->next);
        $stock = $list->items[0]->stock;
        self::assertNotNull($stock);
        self::assertSame([10, 4, 6, 'in_stock', 3], [$stock->physical, $stock->reserved, $stock->available, $stock->level, $stock->version]);
        self::assertNull($list->items[1]->stock);
    }

    /**
     * Saves products named "Produkt 01", "Produkt 02", … in one category.
     *
     * @return list<string> their ids in the order of the list
     */
    private function products(int $count): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $ids = [];
        for ($n = 1; $n <= $count; ++$n) {
            $product = ProductFactory::createOne(['mainCategory' => $category, 'name' => \sprintf('Produkt %02d', $n)]);
            new DoctrineProductRepository($this->entityManager)->save($product);
            $ids[] = $product->id()->toString();
        }

        return $ids;
    }

    private function ask(ListBackofficeStock $query): StockList
    {
        $list = $this->queryBus->dispatch($query)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StockList::class, $list);

        return $list;
    }
}
