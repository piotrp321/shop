<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/me/orders: the customer of the token places an order of the cart of the customer; the email comes
 * from the token.
 */
final class CustomerOrderEndpointTest extends WebTestCase
{
    use Factories;
    use OrderEndpointTestTrait;
    use OrderRequestViolationCases;

    private const string PATH = '/api/v1/me/orders';
    private const string TOKEN_EMAIL = 'jan.kowalski@example.com';
    private const string OTHER_CUSTOMER = 'another-customer';

    public function testACustomerPlacesAnOrderOfTheCartWithTheEmailOfTheToken(): void
    {
        $shoes = $this->variant(12_300, 10);
        $map = $this->variant(1_230, 5, 'Mapa');
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1], [$map, 3]]);

        $order = $this->place(self::PATH, [...self::details(12_300 + 3 * 1_230), 'email' => 'someone.else@example.com'], token: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(201);
        $this->assertNoStore();
        $this->assertOrderView($order);
        self::assertSame(self::TOKEN_EMAIL, $order['email'], 'the email of the body is ignored');
        self::assertSame(self::CONTACT, array_intersect_key($order, self::CONTACT));
        self::assertSame([
            ['variantId' => $shoes, 'productName' => 'Buty Puls', 'variantLabel' => '', 'quantity' => 1, 'unitGross' => 12_300, 'grossBeforeDiscount' => 12_300, 'discountGross' => 0, 'lineGross' => 12_300],
            ['variantId' => $map, 'productName' => 'Mapa', 'variantLabel' => '', 'quantity' => 3, 'unitGross' => 1_230, 'grossBeforeDiscount' => 3_690, 'discountGross' => 0, 'lineGross' => 3_690],
        ], $order['lines']);
        self::assertSame(['net' => 13_000, 'vat' => 2_990, 'gross' => 15_990, 'currency' => 'PLN'], $order['totals']);
        self::assertNull($order['accessToken'], 'a customer reaches the order by the token of the session');

        self::assertSame(1, OrderingFixture::count($this->connection, "SELECT COUNT(*) FROM ordering_order WHERE id = ? AND owner_type = 'customer' AND customer_id = ? AND email = ?", [$order['orderId'], TestTokenFactory::CUSTOMER_ID, self::TOKEN_EMAIL]));
        self::assertIsString($order['orderId']);
        $reserved = [$shoes => 1, $map => 3];
        ksort($reserved);
        self::assertSame($reserved, OrderingFixture::reservation($this->connection, $order['orderId']));
        self::assertSame([], CartFixture::lines($this->connection, $cart));
    }

    public function testARepeatAnswers200WithTheSameOrderAndNoSecondReservation(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]);
        $first = $this->place(self::PATH, self::details(12_300), token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(201);
        CartFixture::line($this->connection, $cart, $shoes, 2);

        $repeated = $this->place(self::PATH, self::details(12_300), token: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame($first, $repeated);
        self::assertArrayHasKey('accessToken', $repeated);
        self::assertNull($repeated['accessToken']);
        self::assertSame(1, $this->orders());
        self::assertSame(1, $this->reservations());
        self::assertSame([10, 1], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame([[$shoes, 2]], CartFixture::lines($this->connection, $cart));
    }

    public function testTheKeyOfAnotherCustomerIsAConflictWithoutTheDataOfItsOrder(): void
    {
        $shoes = $this->variant(12_300, 10);
        CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]);
        $other = CartFixture::cart($this->connection, customerId: self::OTHER_CUSTOMER, lines: [[$shoes, 1]]);
        $earlier = $this->place(self::PATH, self::details(12_300), token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(201);

        $this->assertKeyReused($this->place(self::PATH, self::details(12_300), token: TestTokenFactory::customer(['sub' => self::OTHER_CUSTOMER, 'email' => 'anna@example.com'])), $earlier);

        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $other));
        self::assertSame(1, $this->orders());
        self::assertSame(1, $this->reservations());
    }

    public function testTheKeyOfAGuestOrderOrWithOtherContentIsAConflict(): void
    {
        $shoes = $this->variant(12_300, 10);
        $guestCart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]);
        $guestOrder = $this->place('/api/v1/orders', ['cartId' => $guestCart, 'email' => self::TOKEN_EMAIL, ...self::details(12_300)]);
        self::assertResponseStatusCodeSame(201);

        $this->assertKeyReused($this->place(self::PATH, self::details(12_300), token: TestTokenFactory::customer()), $guestOrder);

        $own = $this->place(self::PATH, self::details(12_300), key: 'own-key', token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(201);
        CartFixture::line($this->connection, $cart, $shoes, 1);
        $this->assertKeyReused($this->place(self::PATH, [...self::details(12_300), 'phone' => '600100201'], key: 'own-key', token: TestTokenFactory::customer()), $own);
        $this->assertKeyReused($this->place(self::PATH, self::details(12_300), key: 'own-key', token: TestTokenFactory::customer(['email' => 'jan@example.com'])), $own);

        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame(2, $this->orders());
    }

    public function testACustomerWithoutACartOrWithAnExpiredOneHasAnEmptyCart(): void
    {
        $this->assertConflict('cart-empty', 'Koszyk jest pusty.', $this->place(self::PATH, self::details(0), token: TestTokenFactory::customer()));

        $shoes = $this->variant(12_300, 10);
        CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, expiresAt: '-1 second', lines: [[$shoes, 1]]);
        $this->assertConflict('cart-empty', 'Koszyk jest pusty.', $this->place(self::PATH, self::details(12_300), token: TestTokenFactory::customer()));

        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    public function testALineWithoutAPriceIsAConflict(): void
    {
        $shoes = $this->variant(12_300, 10);
        $unpriced = $this->variant(null, 10);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1], [$unpriced, 1]]);

        $this->assertConflict('cart-not-orderable', 'Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.', $this->place(self::PATH, self::details(12_300), token: TestTokenFactory::customer()));

        self::assertSame([[$shoes, 1], [$unpriced, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame(0, $this->orders());
    }

    public function testAShortageIsAConflictAndChangesNothing(): void
    {
        $shoes = $this->variant(12_300, 1);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 2]]);

        $this->assertConflict('insufficient-stock-for-order', 'Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.', $this->place(self::PATH, self::details(24_600), token: TestTokenFactory::customer()));

        self::assertSame([[$shoes, 2]], CartFixture::lines($this->connection, $cart));
        self::assertSame([1, 0], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function wrongTotals(): iterable
    {
        yield 'one grosz lower' => [12_299];
        yield 'zero' => [0];
        yield 'one grosz higher' => [12_301];
    }

    #[DataProvider('wrongTotals')]
    public function testAnotherExpectedTotalIsAConflictWithoutOrderOrReservation(int $expected): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]);

        $this->assertConflict('order-total-changed', 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', $this->place(self::PATH, self::details($expected), token: TestTokenFactory::customer()));

        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    public function testLinesQuantitiesAndPricesInTheBodyAreIgnored(): void
    {
        $shoes = $this->variant(12_300, 10);
        CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]);

        $order = $this->place(self::PATH, [
            ...self::details(12_300),
            'lines' => [['variantId' => $shoes, 'quantity' => 5, 'unitGross' => 100]],
            'totals' => ['net' => 81, 'vat' => 19, 'gross' => 100, 'currency' => 'PLN'],
            'unitGross' => 100,
            'price' => 100,
            'cartId' => '0192f0c4-7c1a-7c4e-9a6e-000000000001',
        ], token: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(201);
        self::assertSame([['variantId' => $shoes, 'productName' => 'Buty Puls', 'variantLabel' => '', 'quantity' => 1, 'unitGross' => 12_300, 'grossBeforeDiscount' => 12_300, 'discountGross' => 0, 'lineGross' => 12_300]], $order['lines']);
        self::assertSame(['net' => 10_000, 'vat' => 2_300, 'gross' => 12_300, 'currency' => 'PLN'], $order['totals']);
        self::assertIsString($order['orderId']);
        self::assertSame([$shoes => 1], OrderingFixture::reservation($this->connection, $order['orderId']));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingOrMalformedKeys(): iterable
    {
        yield 'no header' => [null];
        yield 'an empty key' => [''];
        yield 'a key with a space' => ['key 1'];
        yield 'a key of 101 characters' => [str_repeat('k', 101)];
    }

    #[DataProvider('missingOrMalformedKeys')]
    public function testAMissingOrMalformedKeyIsABadRequestAndPlacesNothing(?string $key): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]);

        $problem = $this->place(self::PATH, self::details(12_300), key: $key, token: TestTokenFactory::customer());

        $this->assertProblem(400, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertSame(['Idempotency-Key'], array_column($problem['violations'], 'propertyPath'));
        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame(0, $this->orders());
    }

    public function testBadJsonIs400AndAnotherContentTypeIs415(): void
    {
        $token = TestTokenFactory::customer();

        $this->assertProblem(400, null, $this->place(self::PATH, '{"fullName": ', token: $token));
        $this->assertProblem(415, null, $this->place(self::PATH, 'fullName=Anna', token: $token, contentType: 'application/x-www-form-urlencoded'));
        $this->assertProblem(415, null, $this->place(self::PATH, (string) json_encode(self::details(0)), token: $token, contentType: 'text/plain'));
        self::assertSame(0, $this->orders());
    }

    /**
     * @param array<string, mixed>        $changes
     * @param list<array{string, string}> $violations
     */
    #[DataProvider('invalidCustomerBodies')]
    public function testAnInvalidBodyGivesPolishViolationsAndPlacesNothing(array $changes, array $violations): void
    {
        CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$this->variant(12_300, 10), 1]]);
        $body = self::changed(self::details(12_300), $changes);

        $this->assertViolations($violations, $this->place(self::PATH, [] === $body ? '{}' : $body, token: TestTokenFactory::customer()));

        self::assertSame(0, $this->orders());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'garbage' => ['abc'];
        yield 'staff token' => ['staff'];
        yield 'expired customer token' => ['expired'];
    }

    #[DataProvider('refusedTokens')]
    public function testWithoutATokenOrWithAStaffTokenItIs401AndPlacesNothing(?string $token): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]);
        $token = match ($token) {
            'staff' => TestTokenFactory::staff(['admin']),
            'expired' => TestTokenFactory::customer(['iat' => time() - 600, 'exp' => time() - 1]),
            default => $token,
        };

        $this->assertProblem(401, 'Authentication is required.', $this->place(self::PATH, self::details(12_300), token: $token));

        self::assertSame('Bearer', $this->response()->headers->get('WWW-Authenticate'));
        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame(0, $this->orders());
    }
}
