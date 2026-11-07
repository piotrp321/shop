<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Cart\Domain\CartNotFound;
use App\Cart\Domain\GuestCartMerged;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Command\ReservationNotFound;
use App\Inventory\Application\Command\ReservationStateConflict;
use App\Ordering\Application\CartReference;
use App\Ordering\Application\Port\CartLineToOrder;
use App\Ordering\Application\Port\CatalogVariant;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\Cart\CommandBusOrderCart;
use App\Ordering\Infrastructure\Catalog\QueryBusOrderCatalog;
use App\Ordering\Infrastructure\Inventory\CommandBusStockReservations;
use App\Ordering\Infrastructure\Pricing\QueryBusOrderPrices;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Ordering's ports answered by Cart, Pricing, Catalog and Inventory through their commands and queries on the buses,
 * on shop_test (rolled back after each test).
 */
final class BusAdaptersTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    public function testTheCartIsLockedForTheOrderAndGivesItsLinesInTheOrderTheyWereAdded(): void
    {
        $first = Uuid::v7()->toRfc4122();
        $second = Uuid::v7()->toRfc4122();
        $cart = CartFixture::cart($this->connection, expiresAt: '+3 days', lines: [[$second, 3], [$first, 1]]);

        $lines = $this->cart()->lockForOrder(CartReference::guest($cart));

        self::assertEquals([new CartLineToOrder(VariantId::fromString($second), 3), new CartLineToOrder(VariantId::fromString($first), 1)], $lines);
    }

    public function testTheCartOfACustomerIsLockedByTheSub(): void
    {
        $variant = Uuid::v7()->toRfc4122();
        CartFixture::cart($this->connection, customerId: 'kc-ordering-1', expiresAt: '+3 days', lines: [[$variant, 2]]);

        self::assertEquals([new CartLineToOrder(VariantId::fromString($variant), 2)], $this->cart()->lockForOrder(CartReference::customer('kc-ordering-1')));
        self::assertSame([], $this->cart()->lockForOrder(CartReference::customer('kc-ordering-without-cart')));
    }

    public function testTheExceptionsOfCartArePassedOnAsTheyAre(): void
    {
        $merged = CartFixture::cart($this->connection, expiresAt: '+3 days', status: 'merged');
        $expired = CartFixture::cart($this->connection, expiresAt: '-1 day', lines: [[Uuid::v7()->toRfc4122(), 1]]);
        $customerCart = CartFixture::cart($this->connection, customerId: 'kc-ordering-2', expiresAt: '+3 days');

        foreach ([[$merged, GuestCartMerged::class], [$expired, CartNotFound::class], [$customerCart, CartNotFound::class], [Uuid::v4()->toRfc4122(), CartNotFound::class]] as [$id, $expected]) {
            try {
                $this->cart()->lockForOrder(CartReference::guest($id));
                self::fail('Expected '.$expected);
            } catch (\Throwable $refused) {
                self::assertInstanceOf($expected, $refused);
            }
        }
    }

    public function testClearAfterOrderEmptiesTheCartAndRenewsItsExpiry(): void
    {
        $cart = CartFixture::cart($this->connection, expiresAt: '+3 days', lines: [[Uuid::v7()->toRfc4122(), 1]]);
        $this->cart()->lockForOrder(CartReference::guest($cart));

        $this->cart()->clearAfterOrder(CartReference::guest($cart));

        self::assertSame([], CartFixture::lines($this->connection, $cart));
        $expiresAt = $this->connection->fetchOne('SELECT expires_at FROM cart_cart WHERE id = ?', [$cart]);
        self::assertIsString($expiresAt);
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), new \DateTimeImmutable($expiresAt));
    }

    public function testPricingGivesCopiesOfTheCurrentPricesOfKnownVariantsOnly(): void
    {
        $standard = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 12_300]);
        $reduced = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 1_080, 'taxClass' => 'energy_gel', 'vatRateBp' => 800]);
        // A price at a rate that is no longer the rate of its class is no current price.
        $outdated = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 5_000, 'vatRateBp' => 800]);
        $unknown = Uuid::v7()->toRfc4122();

        $prices = new QueryBusOrderPrices($this->queryBus())->current(array_map(VariantId::fromString(...), [$reduced, $outdated, $unknown, $standard]));

        self::assertSame([$reduced, $standard], array_keys($prices));
        self::assertSame([10_000, 2_300, 12_300, 2300, 'standard'], self::priceData($prices[$standard]));
        self::assertSame([1_000, 80, 1_080, 800, 'energy_gel'], self::priceData($prices[$reduced]));
    }

    public function testCatalogGivesTheNamesAndLabelsOfKnownVariantsOnly(): void
    {
        [$colours, [$black, $red]] = $this->product([Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()], 'Buty Puls');
        [$plain, [$default]] = $this->product([Uuid::v7()->toRfc4122()], 'Mapa Gorce');

        $variants = new QueryBusOrderCatalog($this->queryBus())->variants(array_map(VariantId::fromString(...), [$red, Uuid::v7()->toRfc4122(), $default, $black]));

        self::assertSame([$red, $default, $black], array_keys($variants));
        self::assertSame([$colours, 'Buty Puls', 'kolor 1'], self::variantData($variants[$red]));
        self::assertSame([$plain, 'Mapa Gorce', ''], self::variantData($variants[$default]));
    }

    public function testInventoryReservesEveryLineWithTheOrderIdAsTheKey(): void
    {
        $a = StockFixture::stock($this->connection, 5);
        $b = StockFixture::stock($this->connection, 2);
        $orderId = Uuid::v7()->toRfc4122();

        $this->reservations()->reserve(OrderId::fromString($orderId), [new CartLineToOrder(VariantId::fromString($a), 3), new CartLineToOrder(VariantId::fromString($b), 2)]);

        $expected = [$a => 3, $b => 2];
        ksort($expected);
        self::assertSame($expected, OrderingFixture::reservation($this->connection, $orderId));
        self::assertSame([5, 3], OrderingFixture::stock($this->connection, $a));
        self::assertSame([2, 2], OrderingFixture::stock($this->connection, $b));
    }

    public function testAShortageOfAnyLineReservesNothingAndBecomesInsufficientStockForOrder(): void
    {
        $a = StockFixture::stock($this->connection, 5);
        $b = StockFixture::stock($this->connection, 1);
        $orderId = Uuid::v7()->toRfc4122();

        try {
            $this->reservations()->reserve(OrderId::fromString($orderId), [new CartLineToOrder(VariantId::fromString($a), 3), new CartLineToOrder(VariantId::fromString($b), 2)]);
            self::fail('Expected InsufficientStockForOrder.');
        } catch (InsufficientStockForOrder $shortage) {
            self::assertSame('Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.', $shortage->getMessage());
        }

        self::assertSame([], OrderingFixture::reservation($this->connection, $orderId));
        self::assertSame([5, 0], OrderingFixture::stock($this->connection, $a));
    }

    public function testInventoryCommitsTheReservationOfTheOrderOnceAndPassesItsFailuresOn(): void
    {
        $a = StockFixture::stock($this->connection, 5);
        $orderId = OrderId::fromString(Uuid::v7()->toRfc4122());
        $this->reservations()->reserve($orderId, [new CartLineToOrder(VariantId::fromString($a), 3)]);

        $this->reservations()->commit($orderId);
        $this->reservations()->commit($orderId);

        self::assertSame([2, 0], OrderingFixture::stock($this->connection, $a), 'the units leave the shelf once');
        self::assertSame('committed', $this->connection->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId->toString()]));
        try {
            $this->reservations()->release($orderId);
            self::fail('Expected ReservationStateConflict.');
        } catch (ReservationStateConflict) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->reservations()->commit(OrderId::fromString(Uuid::v7()->toRfc4122()));
            self::fail('Expected ReservationNotFound.');
        } catch (ReservationNotFound) {
            $this->addToAssertionCount(1);
        }
    }

    private function cart(): CommandBusOrderCart
    {
        return new CommandBusOrderCart($this->commandBus());
    }

    private function reservations(): CommandBusStockReservations
    {
        return new CommandBusStockReservations($this->commandBus());
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function queryBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * @return list<int|string>
     */
    private static function priceData(PriceCopy $price): array
    {
        return [$price->net()->amount(), $price->vat()->amount(), $price->gross()->amount(), $price->vatRateBp(), $price->taxClass()];
    }

    /**
     * @return list<string>
     */
    private static function variantData(CatalogVariant $variant): array
    {
        return [$variant->productId->toString(), $variant->productName, $variant->variantLabel];
    }

    /**
     * @param list<string> $variantIds one id for the default variant, more for variants of a colour axis
     *
     * @return array{string, list<string>} the id of the product and the ids of its variants
     */
    private function product(array $variantIds, string $name): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $variants = 1 === \count($variantIds)
            ? ['variantAxes' => [], 'variants' => [['id' => $variantIds[0], 'values' => []]]]
            : ['variantAxes' => ['Kolor'], 'variants' => array_map(static fn (string $id, int $n): array => ['id' => $id, 'values' => ['kolor '.$n]], $variantIds, array_keys($variantIds))];
        $product = ProductFactory::createOne(['mainCategory' => $category, 'name' => $name, ...$variants]);
        new DoctrineProductRepository($this->entityManager)->save($product);

        return [$product->id()->toString(), $variantIds];
    }
}
