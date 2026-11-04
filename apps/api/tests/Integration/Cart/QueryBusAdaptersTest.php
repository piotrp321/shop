<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use App\Cart\Application\Port\ProductAvailability;
use App\Cart\Domain\ProductId;
use App\Cart\Infrastructure\Catalog\QueryBusProductCatalog;
use App\Cart\Infrastructure\Inventory\QueryBusStockAvailability;
use App\Cart\Infrastructure\Pricing\QueryBusCartPrices;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\StatementCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Cart's ports answered by Catalog, Pricing and Inventory through their queries on the query bus, on shop_test
 * (rolled back after each test).
 */
final class QueryBusAdaptersTest extends KernelTestCase
{
    use Factories;

    private MessageBusInterface $queryBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testTheCatalogGivesNamesAndListingImagesOfKnownProductsOnly(): void
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'name' => 'Buty Puls', 'imageAlts' => ['Biegaczka']]);
        new DoctrineProductRepository($this->entityManager)->save($product);
        $id = ProductId::fromString($product->id()->toString());

        $summaries = new QueryBusProductCatalog($this->queryBus)->summaries([ProductId::fromString((string) Uuid::v7()), $id]);

        self::assertCount(1, $summaries);
        self::assertTrue($id->equals($summaries[0]->id));
        self::assertSame('Buty Puls', $summaries[0]->name);
        self::assertSame('Biegaczka', $summaries[0]->image?->alt);
        self::assertSame('listing', $summaries[0]->image->size);
        self::assertSame([256, 384, 512, 768], array_column($summaries[0]->image->variants['webp'] ?? [], 'width'));
    }

    /**
     * Transitional until the cart lines are kept per variant (VARIANTS-4): a product with exactly one variant has the
     * current price of that variant, a product with more variants has none.
     */
    public function testPricingGivesTheCurrentGrossPriceOfTheOnlyVariantOfAProduct(): void
    {
        $priced = $this->product([(string) Uuid::v7()]);
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $priced[1][0], 'gross' => 19_999]);
        // A price at a rate that is no longer the rate of its class is no current price.
        $outdated = $this->product([(string) Uuid::v7()]);
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $outdated[1][0], 'gross' => 5_000, 'vatRateBp' => 800]);
        $twoVariants = $this->product([(string) Uuid::v7(), (string) Uuid::v7()]);
        foreach ($twoVariants[1] as $variantId) {
            VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $variantId, 'gross' => 7_000]);
        }
        self::assertNotSame($priced[0], $priced[1][0]);

        $prices = new QueryBusCartPrices($this->queryBus)->current(array_map(
            static fn (string $id): ProductId => ProductId::fromString($id),
            [$twoVariants[0], $priced[0], $outdated[0], (string) Uuid::v7()],
        ));

        self::assertCount(1, $prices);
        self::assertSame($priced[0], $prices[0]->productId->toString());
        self::assertSame(19_999, $prices[0]->gross->amount());
    }

    public function testInventoryGivesTheAvailableUnitsOfEveryProduct(): void
    {
        $stocked = StockFixture::stock($this->entityManager->getConnection(), 10, 3);
        $unknown = (string) Uuid::v7();

        $levels = new QueryBusStockAvailability($this->queryBus)->of([ProductId::fromString($stocked), ProductId::fromString($unknown)]);

        self::assertEquals([new ProductAvailability(ProductId::fromString($stocked), 7), new ProductAvailability(ProductId::fromString($unknown), 0)], $levels);
    }

    public function testNoProductAsksNoQuery(): void
    {
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $before = $counter->count();

        self::assertSame([], new QueryBusProductCatalog($this->queryBus)->summaries([]));
        self::assertSame([], new QueryBusCartPrices($this->queryBus)->current([]));
        self::assertSame([], new QueryBusStockAvailability($this->queryBus)->of([]));

        self::assertSame($before, $counter->count());
    }

    /**
     * @param list<string> $variantIds one id for the default variant, more for variants of a colour axis
     *
     * @return array{string, list<string>} the id of the product and the ids of its variants
     */
    private function product(array $variantIds): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $variants = 1 === \count($variantIds)
            ? ['variantAxes' => [], 'variants' => [['id' => $variantIds[0], 'values' => []]]]
            : ['variantAxes' => ['Kolor'], 'variants' => array_map(static fn (string $id, int $n): array => ['id' => $id, 'values' => ['kolor '.$n]], $variantIds, array_keys($variantIds))];
        $product = ProductFactory::createOne(['mainCategory' => $category, ...$variants]);
        new DoctrineProductRepository($this->entityManager)->save($product);

        return [$product->id()->toString(), $variantIds];
    }
}
