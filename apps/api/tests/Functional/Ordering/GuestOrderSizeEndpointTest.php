<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/orders and /api/v1/me/orders: a guest order has at most 10 lines of 10 units (ABUSE-LIMITS, L5, D5);
 * the order of a customer keeps its 50 lines of 99 units.
 */
final class GuestOrderSizeEndpointTest extends WebTestCase
{
    use Factories;
    use OrderEndpointTestTrait;

    private const string PATH = '/api/v1/orders';
    private const string CUSTOMER_PATH = '/api/v1/me/orders';
    private const string EMAIL = 'anna.nowak@example.com';
    private const string TOO_LARGE = 'Zamówienie bez konta może mieć najwyżej 10 różnych produktów, po 10 sztuk każdego. Zmniejsz ilości w koszyku.';

    public function testAGuestCartOfElevenLinesIsAConflictAndChangesNothing(): void
    {
        $variants = $this->variantsOfOneGross(11);
        $cart = CartFixture::cart($this->connection, lines: array_map(static fn (string $id): array => [$id, 1], $variants));

        $this->assertConflict('guest-order-too-large', self::TOO_LARGE, $this->place(self::PATH, $this->body($cart, 11 * 1_000)));

        $this->assertNothingPlaced($cart, 11, $variants);
    }

    public function testAGuestCartWithElevenUnitsOfOneLineIsAConflictAndChangesNothing(): void
    {
        $variants = $this->variantsOfOneGross(1, 50);
        $cart = CartFixture::cart($this->connection, lines: [[$variants[0], 11]]);

        $this->assertConflict('guest-order-too-large', self::TOO_LARGE, $this->place(self::PATH, $this->body($cart, 11 * 1_000)));

        $this->assertNothingPlaced($cart, 1, $variants, 50);
    }

    public function testAGuestCartOfElevenLinesWithACodeIsAConflictWithoutAUsageOfTheCode(): void
    {
        $this->code(['code' => 'LIMIT-L5', 'usageLimit' => 1]);
        $variants = $this->variantsOfOneGross(11);
        $cart = CartFixture::cart($this->connection, lines: array_map(static fn (string $id): array => [$id, 1], $variants));

        $this->assertConflict('guest-order-too-large', self::TOO_LARGE, $this->place(self::PATH, [...$this->body($cart, 11 * 1_000), 'discountCode' => 'limit-l5']));

        $this->assertNothingPlaced($cart, 11, $variants);
    }

    public function testTheSizeIsRefusedBeforeAVariantThatCannotBeBought(): void
    {
        $variants = $this->variantsOfOneGross(10);
        $withoutPrice = $this->variant(null, 10);
        $cart = CartFixture::cart($this->connection, lines: array_map(static fn (string $id): array => [$id, 1], [...$variants, $withoutPrice]));

        $this->assertConflict('guest-order-too-large', self::TOO_LARGE, $this->place(self::PATH, $this->body($cart, 11 * 1_000)));

        self::assertSame(0, $this->orders());
    }

    public function testAGuestCartOfTenLinesOfTenUnitsIsOrdered(): void
    {
        $variants = $this->variantsOfOneGross(10, 50);
        $cart = CartFixture::cart($this->connection, lines: array_map(static fn (string $id): array => [$id, 10], $variants));

        $order = $this->place(self::PATH, $this->body($cart, 10 * 10 * 1_000));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::CONTACT, array_intersect_key($order, self::CONTACT));
        self::assertIsArray($order['lines']);
        self::assertCount(10, $order['lines']);
    }

    public function testTwoGuestCartsAreCheckedEachByItsOwnSize(): void
    {
        $variants = $this->variantsOfOneGross(11);
        $big = CartFixture::cart($this->connection, lines: array_map(static fn (string $id): array => [$id, 1], $variants));
        $small = CartFixture::cart($this->connection, lines: [[$variants[0], 1]]);

        $this->assertConflict('guest-order-too-large', self::TOO_LARGE, $this->place(self::PATH, $this->body($big, 11 * 1_000), key: 'key-big'));
        $this->place(self::PATH, $this->body($small, 1_000), key: 'key-small');
        self::assertResponseStatusCodeSame(201);
        $this->assertConflict('guest-order-too-large', self::TOO_LARGE, $this->place(self::PATH, $this->body($big, 11 * 1_000), key: 'key-big-2'));

        self::assertSame(1, $this->orders());
    }

    public function testACustomerOrdersFiftyLinesOfNinetyNineUnits(): void
    {
        $variants = $this->variantsOfOneGross(50, 100);
        CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: array_map(static fn (string $id): array => [$id, 99], $variants));

        $order = $this->place(self::CUSTOMER_PATH, self::details(50 * 99 * 1_000), token: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($order['lines']);
        self::assertCount(50, $order['lines']);
    }

    /**
     * @param list<string> $variants
     */
    private function assertNothingPlaced(string $cart, int $lines, array $variants, int $stock = 10): void
    {
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
        self::assertCount($lines, CartFixture::lines($this->connection, $cart), 'the cart keeps its lines');
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM pricing_discount_code_usage'));
        self::assertSame(0, OrderingFixture::count($this->connection, "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'scheduled'"), 'no ExpireUnpaidOrder');
        self::assertSame(0, OrderingFixture::count($this->connection, "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox'"), 'no OrderPlaced');
        self::assertSame([$stock, 0], OrderingFixture::stock($this->connection, $variants[0]), 'nothing reserved');
    }

    /**
     * @return list<string> variants of 10,00 zł
     */
    private function variantsOfOneGross(int $count, int $stock = 10): array
    {
        $variants = [];
        for ($i = 1; $i <= $count; ++$i) {
            $variants[] = $this->variant(1_000, $stock, 'Produkt '.$i);
        }

        return $variants;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function code(array $attributes): DiscountCode
    {
        $code = DiscountCodeFactory::createOne($attributes);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $cartId, int $expectedTotalGross): array
    {
        return ['cartId' => $cartId, 'email' => self::EMAIL, ...self::details($expectedTotalGross)];
    }
}
