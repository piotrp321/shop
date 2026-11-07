<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use App\Cart\Application\Port\CartPrice;
use App\Cart\Application\Port\ProductVariants;
use App\Cart\Application\Port\VariantAvailability;
use App\Cart\Application\Port\VariantLabel;
use App\Cart\Application\Port\VariantSummary;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\StockLevel;
use App\Cart\Domain\VariantId;
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

    public function testTheCatalogGivesNamesLabelsAndListingImagesOfKnownVariantsOnly(): void
    {
        [$productId, [$black, $red]] = $this->product([(string) Uuid::v7(), (string) Uuid::v7()], 'Buty Puls', ['Biegaczka']);

        $summaries = new QueryBusProductCatalog($this->queryBus)->summaries([VariantId::fromString((string) Uuid::v7()), VariantId::fromString($red), VariantId::fromString($black)]);

        self::assertSame([$red, $black], array_map(static fn (VariantSummary $s): string => $s->variantId->toString(), $summaries));
        self::assertSame([$productId, $productId], array_map(static fn (VariantSummary $s): string => $s->productId->toString(), $summaries));
        self::assertSame(['kolor 1', 'kolor 0'], array_map(static fn (VariantSummary $s): string => $s->label, $summaries));
        self::assertSame('Buty Puls', $summaries[0]->name);
        self::assertSame('Biegaczka', $summaries[0]->image?->alt);
        self::assertSame('listing', $summaries[0]->image->size);
        self::assertSame([256, 384, 512, 768], array_column($summaries[0]->image->variants['webp'] ?? [], 'width'));
    }

    public function testTheCatalogGivesTheAxesAndVariantsOfKnownProductsOnly(): void
    {
        [$colours, [$black, $red]] = $this->product([(string) Uuid::v7(), (string) Uuid::v7()]);
        [$plain, [$default]] = $this->product([(string) Uuid::v7()]);

        $lists = new QueryBusProductCatalog($this->queryBus)->variantsOf([ProductId::fromString($plain), ProductId::fromString((string) Uuid::v7()), ProductId::fromString($colours)]);

        self::assertEquals([
            new ProductVariants(ProductId::fromString($plain), [], [new VariantLabel(VariantId::fromString($default), '')]),
            new ProductVariants(ProductId::fromString($colours), ['Kolor'], [new VariantLabel(VariantId::fromString($black), 'kolor 0'), new VariantLabel(VariantId::fromString($red), 'kolor 1')]),
        ], $lists);
    }

    public function testPricingGivesTheCurrentGrossPriceOfEveryVariant(): void
    {
        [$productId, [$black, $red, $outdated]] = $this->product([(string) Uuid::v7(), (string) Uuid::v7(), (string) Uuid::v7()]);
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $black, 'gross' => 19_999]);
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $red, 'gross' => 21_000]);
        // A price at a rate that is no longer the rate of its class is no current price.
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $outdated, 'gross' => 5_000, 'vatRateBp' => 800]);

        $prices = new QueryBusCartPrices($this->queryBus)->current(array_map(
            static fn (string $id): VariantId => VariantId::fromString($id),
            [$red, $outdated, $productId, $black, (string) Uuid::v7()],
        ));

        self::assertSame([[$red, 21_000], [$black, 19_999]], array_map(static fn (CartPrice $p): array => [$p->variantId->toString(), $p->gross->amount()], $prices));
    }

    public function testInventoryGivesTheAvailableUnitsAndTheLevelOfEveryVariant(): void
    {
        $connection = $this->entityManager->getConnection();
        [, [$many, $few, $none]] = $this->product([(string) Uuid::v7(), (string) Uuid::v7(), (string) Uuid::v7()]);
        StockFixture::stock($connection, 10, 3, variantId: $many);
        StockFixture::stock($connection, 2, variantId: $few);
        StockFixture::stock($connection, 0, variantId: $none);
        $unknown = (string) Uuid::v7();

        $levels = new QueryBusStockAvailability($this->queryBus)->of(array_map(
            static fn (string $id): VariantId => VariantId::fromString($id),
            [$many, $few, $none, $unknown],
        ));

        self::assertEquals([
            new VariantAvailability(VariantId::fromString($many), 7, StockLevel::InStock),
            new VariantAvailability(VariantId::fromString($few), 2, StockLevel::LowStock),
            new VariantAvailability(VariantId::fromString($none), 0, StockLevel::OutOfStock),
            new VariantAvailability(VariantId::fromString($unknown), 0, StockLevel::OutOfStock),
        ], $levels);
    }

    public function testNoIdAsksNoQuery(): void
    {
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $before = $counter->count();

        self::assertSame([], new QueryBusProductCatalog($this->queryBus)->summaries([]));
        self::assertSame([], new QueryBusProductCatalog($this->queryBus)->variantsOf([]));
        self::assertSame([], new QueryBusCartPrices($this->queryBus)->current([]));
        self::assertSame([], new QueryBusStockAvailability($this->queryBus)->of([]));

        self::assertSame($before, $counter->count());
    }

    /**
     * @param list<string> $variantIds one id for the default variant, more for variants of a colour axis
     * @param list<string> $imageAlts
     *
     * @return array{string, list<string>} the id of the product and the ids of its variants
     */
    private function product(array $variantIds, string $name = 'Produkt', array $imageAlts = []): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $variants = 1 === \count($variantIds)
            ? ['variantAxes' => [], 'variants' => [['id' => $variantIds[0], 'values' => []]]]
            : ['variantAxes' => ['Kolor'], 'variants' => array_map(static fn (string $id, int $n): array => ['id' => $id, 'values' => ['kolor '.$n]], $variantIds, array_keys($variantIds))];
        $product = ProductFactory::createOne(['mainCategory' => $category, 'name' => $name, 'imageAlts' => $imageAlts, ...$variants]);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return [$product->id()->toString(), $variantIds];
    }
}
