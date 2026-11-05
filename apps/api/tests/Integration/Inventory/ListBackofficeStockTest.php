<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Query\ListBackofficeStock;
use App\Inventory\Application\Query\StockList;
use App\Inventory\Application\Query\StockListItem;
use App\Inventory\Application\Query\StockListVariant;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * ListBackofficeStock on PostgreSQL, through the real query bus and Catalog (shop_test, rolled back after each test):
 * products of a page with their variants.
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

    public function testTheVariantsAndTheStocksOfAPageAreReadWithAFixedNumberOfStatementsWhateverTheSize(): void
    {
        $this->products(25, 3);
        $this->ask(new ListBackofficeStock(null, null, null, 1)); // warms up what is read once per process

        $before = $this->counter->count();
        $one = $this->ask(new ListBackofficeStock(null, null, null, 1));
        $statementsForOne = $this->counter->count() - $before;

        $before = $this->counter->count();
        $page = $this->ask(new ListBackofficeStock(null, null, null, 25));
        $statementsForPage = $this->counter->count() - $before;

        self::assertCount(1, $one->items);
        self::assertCount(25, $page->items);
        self::assertCount(3, $page->items[24]->variants);
        self::assertSame($statementsForOne, $statementsForPage);
    }

    public function testPagesThroughProductsEachWithAllItsVariantsAndTheirStock(): void
    {
        $products = $this->products(3, 2);
        StockFixture::stock($this->connection, 10, 4, 3, $products[0][1][1]);
        StockFixture::stock($this->connection, 2, 0, 1, $products[2][1][0]);

        $first = $this->ask(new ListBackofficeStock(null, null, null, 2));
        self::assertNotNull($first->next);
        $second = $this->ask(new ListBackofficeStock(null, null, $first->next, 2));

        self::assertNull($second->next);
        $listed = array_map(
            static fn (StockListItem $item): array => [$item->productId, array_map(static fn (StockListVariant $variant): array => [$variant->variantId, $variant->stock?->physical], $item->variants)],
            [...$first->items, ...$second->items],
        );
        self::assertSame([
            [$products[0][0], [[$products[0][1][0], null], [$products[0][1][1], 10]]],
            [$products[1][0], [[$products[1][1][0], null], [$products[1][1][1], null]]],
            [$products[2][0], [[$products[2][1][0], 2], [$products[2][1][1], null]]],
        ], $listed);
        $stock = $first->items[0]->variants[1]->stock;
        self::assertNotNull($stock);
        self::assertSame([10, 4, 6, 'in_stock', 3], [$stock->physical, $stock->reserved, $stock->available, $stock->level, $stock->version]);
    }

    public function testAStoredEmptyStockAtVersionZeroIsListedWithItsVersion(): void
    {
        $products = $this->products(1, 1);
        $this->connection->executeStatement('INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at) VALUES (?, 0, 0, 0, now())', [$products[0][1][0]]);

        $stock = $this->ask(new ListBackofficeStock(null, null, null, 25))->items[0]->variants[0]->stock;

        self::assertNotNull($stock);
        self::assertSame([0, 0, 0, 'out_of_stock', 0], [$stock->physical, $stock->reserved, $stock->available, $stock->level, $stock->version]);
    }

    /**
     * Saves products named "Produkt 01", "Produkt 02", … in one category, each with the given number of variants of a
     * colour axis (one: the default variant), whose ids are never the ids of their products.
     *
     * @return list<array{string, list<string>}> the id of each product with the ids of its variants, in the order of the list
     */
    private function products(int $count, int $variantsPerProduct): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $products = [];
        for ($n = 1; $n <= $count; ++$n) {
            $attributes = ['mainCategory' => $category, 'name' => \sprintf('Produkt %02d', $n)];
            if ($variantsPerProduct > 1) {
                $attributes += ['variantAxes' => ['Kolor'], 'variants' => array_map(static fn (int $v): array => ['id' => (string) Uuid::v4(), 'values' => ['kolor '.$v]], range(1, $variantsPerProduct))];
            }
            $product = ProductFactory::createOne($attributes);
            new DoctrineProductRepository($this->entityManager)->save($product);
            $variantIds = array_map(static fn (ProductVariant $variant): string => $variant->id()->toString(), $product->variants()->toList());
            self::assertNotContains($product->id()->toString(), $variantIds);
            $products[] = [$product->id()->toString(), $variantIds];
        }

        return $products;
    }

    private function ask(ListBackofficeStock $query): StockList
    {
        $list = $this->queryBus->dispatch($query)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StockList::class, $list);

        return $list;
    }
}
