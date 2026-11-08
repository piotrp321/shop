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
use App\Ordering\Application\Port\QuotedCodeRejection;
use App\Ordering\Domain\DiscountCodeRejected;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\OrderDiscountKind;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\Cart\CommandBusOrderCart;
use App\Ordering\Infrastructure\Catalog\QueryBusOrderCatalog;
use App\Ordering\Infrastructure\Inventory\CommandBusStockReservations;
use App\Ordering\Infrastructure\Pricing\CommandBusDiscountCodeRedemptions;
use App\Ordering\Infrastructure\Pricing\QueryBusOrderPrices;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\DiscountCodeFactory;
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

    public function testPricingQuotesTheLinesWithCurrentPricesOnly(): void
    {
        $standard = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 12_300]);
        $reduced = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 1_080, 'taxClass' => 'energy_gel', 'vatRateBp' => 800]);
        // A price at a rate that is no longer the rate of its class is no current price.
        $outdated = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 5_000, 'vatRateBp' => 800]);
        $unknown = Uuid::v7()->toRfc4122();

        $quote = new QueryBusOrderPrices($this->queryBus())->quote(self::lines([$reduced, 3], [$outdated, 1], [$unknown, 1], [$standard, 2]), null);

        self::assertSame([$reduced, $standard], array_keys($quote->lines));
        self::assertSame([10_000, 2_300, 12_300, 2300, 'standard'], self::priceData($quote->lines[$standard]->unitPrice));
        self::assertSame([1_000, 80, 1_080, 800, 'energy_gel'], self::priceData($quote->lines[$reduced]->unitPrice));
        self::assertSame([0, 20_000, 4_600, 24_600], self::amountData($quote->lines[$standard]->amounts));
        // The net of the line is computed from its gross (D8): 3 × 10,80 zł at 8 % is 30,00 zł net.
        self::assertSame([0, 3_000, 240, 3_240], self::amountData($quote->lines[$reduced]->amounts));
        self::assertNull($quote->lines[$standard]->promotion);
        self::assertSame(27_840, $quote->subtotalGross);
        self::assertNull($quote->discount);
        self::assertNull($quote->discountCodeId);
        self::assertNull($quote->rejection);
    }

    public function testPricingQuotesThePromotionOfALine(): void
    {
        $promoted = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 10_000]);
        $promotion = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, 'Jesień', 20, NOW() - interval '1 hour', NOW() + interval '1 day', NOW(), NOW(), 1)",
            [$promotion],
        );
        $this->connection->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, ?)', [$promotion, $promoted]);

        $line = new QueryBusOrderPrices($this->queryBus())->quote(self::lines([$promoted, 1]), null)->lines[$promoted];

        self::assertSame(8_000, $line->unitPrice->gross()->amount());
        self::assertNotNull($line->promotion);
        self::assertSame([$promotion, 'Jesień', 20, 10_000], [$line->promotion->promotionId(), $line->promotion->name(), $line->promotion->percentOff(), $line->promotion->regularUnitGross()->amount()]);
    }

    public function testPricingQuotesACodeWithItsCopyAndIdOrItsRejection(): void
    {
        $variant = VariantPriceRecordFactory::store($this->entityManager, ['variantId' => Uuid::v7()->toRfc4122(), 'gross' => 12_300]);
        $code = DiscountCodeFactory::createOne(['code' => 'ORDER-10', 'value' => 10]);
        $limited = DiscountCodeFactory::createOne(['code' => 'ORDER-MIN', 'minimumOrderGross' => 30_000]);
        $codes = new DoctrineDiscountCodeRepository($this->entityManager);
        $codes->save($code);
        $codes->save($limited);
        $prices = new QueryBusOrderPrices($this->queryBus());

        $quote = $prices->quote(self::lines([$variant, 2]), 'ORDER-10');

        self::assertNotNull($quote->discount);
        self::assertSame(['ORDER-10', OrderDiscountKind::Percent, 10, 2_460], [$quote->discount->code(), $quote->discount->kind(), $quote->discount->value(), $quote->discount->amount()->amount()]);
        self::assertSame($code->id()->toString(), $quote->discountCodeId);
        self::assertSame(24_600, $quote->subtotalGross);
        self::assertSame([2_460, 18_000, 4_140, 22_140], self::amountData($quote->lines[$variant]->amounts));

        $rejected = $prices->quote(self::lines([$variant, 2]), 'ORDER-MIN');
        self::assertNull($rejected->discount);
        self::assertNull($rejected->discountCodeId);
        self::assertEquals(new QuotedCodeRejection('tag:piotrp321.github.io,2026:shop/problems/discount-code-below-minimum', 'Ten kod działa przy zamówieniu od 300,00 zł.', 30_000), $rejected->rejection);
        self::assertEquals(new QuotedCodeRejection('tag:piotrp321.github.io,2026:shop/problems/discount-code-invalid', 'Ten kod jest nieprawidłowy albo nieaktywny.', null), $prices->quote(self::lines([$variant, 2]), 'NO-SUCH-CODE')->rejection);
    }

    public function testPricingRecordsAndReleasesTheUsageOfACode(): void
    {
        $code = DiscountCodeFactory::createOne(['usageLimit' => 1]);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);
        $orderId = OrderId::fromString(Uuid::v7()->toRfc4122());
        $redemptions = new CommandBusDiscountCodeRedemptions($this->commandBus());

        $redemptions->redeem($code->id()->toString(), $orderId, 10_000);

        self::assertSame(1, $this->activeUses($code->id()->toString()));
        try {
            $redemptions->redeem($code->id()->toString(), OrderId::fromString(Uuid::v7()->toRfc4122()), 10_000);
            self::fail('Expected DiscountCodeRejected.');
        } catch (DiscountCodeRejected $rejected) {
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-used-up', $rejected->problemType());
            self::assertSame('Ten kod został już wykorzystany.', $rejected->getMessage());
            self::assertSame([], $rejected->problemExtensions());
        }

        $redemptions->release($orderId);
        $redemptions->release($orderId);
        $redemptions->release(OrderId::fromString(Uuid::v7()->toRfc4122()));

        self::assertSame(0, $this->activeUses($code->id()->toString()));
    }

    public function testARejectionBelowTheMinimumKeepsTheMinimum(): void
    {
        $code = DiscountCodeFactory::createOne(['minimumOrderGross' => 30_000]);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        try {
            new CommandBusDiscountCodeRedemptions($this->commandBus())->redeem($code->id()->toString(), OrderId::fromString(Uuid::v7()->toRfc4122()), 29_999);
            self::fail('Expected DiscountCodeRejected.');
        } catch (DiscountCodeRejected $rejected) {
            self::assertSame('tag:piotrp321.github.io,2026:shop/problems/discount-code-below-minimum', $rejected->problemType());
            self::assertSame(['minimumOrderGross' => 30_000], $rejected->problemExtensions());
        }
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
     * @return list<int>
     */
    private static function amountData(LineAmounts $amounts): array
    {
        return [$amounts->discount()->amount(), $amounts->net()->amount(), $amounts->vat()->amount(), $amounts->gross()->amount()];
    }

    /**
     * @param array{string, int} ...$lines variant id and quantity
     *
     * @return non-empty-list<CartLineToOrder>
     */
    private static function lines(array ...$lines): array
    {
        $toOrder = array_map(static fn (array $line): CartLineToOrder => new CartLineToOrder(VariantId::fromString($line[0]), $line[1]), array_values($lines));
        \assert([] !== $toOrder);

        return $toOrder;
    }

    private function activeUses(string $codeId): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM pricing_discount_code_usage WHERE code_id = ? AND released_at IS NULL', [$codeId]);
        \assert(\is_int($count));

        return $count;
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
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return [$product->id()->toString(), $variantIds];
    }
}
