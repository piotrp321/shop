<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/orders: a guest places an order of the whole guest cart, without a token.
 */
final class GuestOrderEndpointTest extends WebTestCase
{
    use Factories;
    use OrderEndpointTestTrait;
    use OrderRequestViolationCases;

    private const string PATH = '/api/v1/orders';
    private const string EMAIL = 'anna.nowak@example.com';

    public function testAGuestPlacesAnOrderOfTheWholeCart(): void
    {
        [$size42, $size43] = $this->variants([[12_300, 10, ['czarne', '42']], [12_300, 10, ['czarne', '43']]], ['Kolor', 'Rozmiar'], 'Buty Asfalt');
        $map = $this->variant(1_230, 5, 'Mapa');
        $cart = CartFixture::cart($this->connection, lines: [[$size43, 2], [$map, 1], [$size42, 1]]);

        $order = $this->place(self::PATH, $this->body($cart, 3 * 12_300 + 1_230));

        self::assertResponseStatusCodeSame(201);
        $this->assertNoStore();
        $this->assertOrderView($order);
        self::assertSame(self::EMAIL, $order['email']);
        self::assertSame(self::CONTACT, array_intersect_key($order, self::CONTACT));
        self::assertSame([
            ['variantId' => $size43, 'productName' => 'Buty Asfalt', 'variantLabel' => 'czarne, 43', 'quantity' => 2, 'unitGross' => 12_300, 'lineGross' => 24_600],
            ['variantId' => $map, 'productName' => 'Mapa', 'variantLabel' => '', 'quantity' => 1, 'unitGross' => 1_230, 'lineGross' => 1_230],
            ['variantId' => $size42, 'productName' => 'Buty Asfalt', 'variantLabel' => 'czarne, 42', 'quantity' => 1, 'unitGross' => 12_300, 'lineGross' => 12_300],
        ], $order['lines'], 'the lines of the cart in its order, with the names of Catalog and the prices of Pricing');
        self::assertSame(['net' => 31_000, 'vat' => 7_130, 'gross' => 38_130, 'currency' => 'PLN'], $order['totals']);
        self::assertIsString($order['accessToken']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $order['accessToken'], 'the access token of the guest');
        self::assertSame($order['accessToken'], $this->connection->fetchOne('SELECT access_token FROM ordering_order WHERE id = ?', [$order['orderId']]));

        self::assertSame(1, OrderingFixture::count($this->connection, "SELECT COUNT(*) FROM ordering_order WHERE id = ? AND number = ? AND owner_type = 'guest' AND customer_id IS NULL AND email = ? AND idempotency_key = ?", [$order['orderId'], $order['number'], self::EMAIL, self::KEY]));
        self::assertIsString($order['orderId']);
        $reserved = [$size42 => 1, $size43 => 2, $map => 1];
        ksort($reserved);
        self::assertSame($reserved, OrderingFixture::reservation($this->connection, $order['orderId']), 'the reservation has the order id as its key');
        self::assertSame([], CartFixture::lines($this->connection, $cart), 'the cart is empty');
        self::assertSame('open', $this->connection->fetchOne('SELECT status FROM cart_cart WHERE id = ?', [$cart]));
    }

    public function testARepeatAnswers200WithTheSameOrderAndNoSecondReservation(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 2]]);
        $first = $this->place(self::PATH, $this->body($cart, 24_600));
        self::assertResponseStatusCodeSame(201);
        CartFixture::line($this->connection, $cart, $shoes, 3);

        $repeated = $this->place(self::PATH, $this->body($cart, 24_600));

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame($first, $repeated);
        self::assertIsString($repeated['accessToken']);
        self::assertSame($first['accessToken'], $repeated['accessToken'], 'the same token on a replay');
        self::assertSame(1, $this->orders());
        self::assertSame(1, $this->reservations());
        self::assertSame([10, 2], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame([[$shoes, 3]], CartFixture::lines($this->connection, $cart), 'the line added after the order stays in the cart');
    }

    public function testARepeatAfterTheCartWasMergedOrExpiredAnswers200(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $first = $this->place(self::PATH, $this->body($cart, 12_300));
        self::assertResponseStatusCodeSame(201);

        $this->connection->executeStatement("UPDATE cart_cart SET status = 'merged', merged_into = ?, merged_at = now() WHERE id = ?", [(string) Uuid::v4(), $cart]);
        self::assertSame($first, $this->place(self::PATH, $this->body($cart, 12_300)));
        self::assertResponseStatusCodeSame(200);

        $this->connection->executeStatement("UPDATE cart_cart SET status = 'open', merged_into = NULL, merged_at = NULL, expires_at = now() - interval '1 second' WHERE id = ?", [$cart]);
        self::assertSame($first, $this->place(self::PATH, $this->body($cart, 12_300)));
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();

        self::assertSame(1, $this->orders());
        self::assertSame(1, $this->reservations());
    }

    public function testTheKeyOfAnotherGuestCartIsAConflictWithoutTheDataOfItsOrder(): void
    {
        $shoes = $this->variant(12_300, 10);
        $first = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $other = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $earlier = $this->place(self::PATH, $this->body($first, 12_300));
        self::assertResponseStatusCodeSame(201);

        $this->assertKeyReused($this->place(self::PATH, $this->body($other, 12_300)), $earlier);

        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $other));
        self::assertSame(1, $this->orders());
        self::assertSame(1, $this->reservations());
    }

    public function testTheKeyWithOtherContentIsAConflictWithoutTheDataOfItsOrder(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $earlier = $this->place(self::PATH, $this->body($cart, 12_300));
        CartFixture::line($this->connection, $cart, $this->variant(1_230, 5), 1);

        foreach ([['fullName' => 'Jan Nowak'], ['email' => 'jan@example.com'], ['city' => 'Kraków'], ['expectedTotalGross' => 1_230]] as $change) {
            $this->assertKeyReused($this->place(self::PATH, [...$this->body($cart, 12_300), ...$change]), $earlier);
        }

        self::assertCount(1, CartFixture::lines($this->connection, $cart));
        self::assertSame(1, $this->orders());
    }

    public function testAnUnknownExpiredMergedOrCustomerCartIsNotFound(): void
    {
        $shoes = $this->variant(12_300, 10);
        $carts = [
            'unknown' => (string) Uuid::v4(),
            'expired' => CartFixture::cart($this->connection, expiresAt: '-1 second', lines: [[$shoes, 1]]),
            'merged' => CartFixture::cart($this->connection, status: 'merged'),
            'customer' => CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$shoes, 1]]),
        ];

        foreach ($carts as $kind => $cartId) {
            $this->assertProblem(404, 'Koszyk nie jest już dostępny.', $this->place(self::PATH, $this->body($cartId, 12_300), key: 'key-'.$kind));
        }

        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $carts['expired']));
        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $carts['customer']));
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    /**
     * The format of a cart id is the one of Cart (any 8-4-4-4-12 hex, any letter case), not a strict RFC UUID: an id
     * with another version or variant, like the nil UUID, is an unknown cart, not a violation.
     */
    public function testAnIdOfTheFormatOfCartWithoutACartIsNotFound(): void
    {
        foreach (['00000000-0000-0000-0000-000000000000', '0192f0c4-7c1a-0c4e-0a6e-000000000001'] as $cartId) {
            $this->assertProblem(404, 'Koszyk nie jest już dostępny.', $this->place(self::PATH, $this->body($cartId, 12_300), key: 'key-'.$cartId));
        }
        self::assertSame(0, $this->orders());
    }

    public function testTheIdOfTheCartInUpperCaseIsTheSameCart(): void
    {
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(12_300, 10), 1]]);

        $order = $this->place(self::PATH, $this->body(strtoupper($cart), 12_300));

        self::assertResponseStatusCodeSame(201);
        self::assertSame($order, $this->place(self::PATH, $this->body($cart, 12_300)), 'a repeat with the id in lower case');
        self::assertResponseStatusCodeSame(200);
    }

    public function testAnEmptyCartIsAConflict(): void
    {
        $cart = CartFixture::cart($this->connection);

        $this->assertConflict('cart-empty', 'Koszyk jest pusty.', $this->place(self::PATH, $this->body($cart, 0)));
        self::assertSame(0, $this->orders());
    }

    public function testALineWithoutAPriceUnknownToCatalogOrOfADraftIsAConflict(): void
    {
        $shoes = $this->variant(12_300, 10);
        foreach (['no price' => $this->variant(null, 10), 'unknown to Catalog' => (string) Uuid::v7(), 'of a draft' => $this->draftVariant(12_300, 10)] as $kind => $variant) {
            $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1], [$variant, 1]]);

            $this->assertConflict('cart-not-orderable', 'Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.', $this->place(self::PATH, $this->body($cart, 12_300), key: 'key-'.$cart));

            self::assertSame([[$shoes, 1], [$variant, 1]], CartFixture::lines($this->connection, $cart), $kind);
        }
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    public function testAShortageIsAConflictWithoutTheNumberOfUnitsAndChangesNothing(): void
    {
        $shoes = $this->variant(12_300, 10);
        $last = $this->variant(1_230, 1);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 2], [$last, 2]]);

        $this->assertConflict('insufficient-stock-for-order', 'Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.', $this->place(self::PATH, $this->body($cart, 2 * 12_300 + 2 * 1_230)));

        self::assertSame([[$shoes, 2], [$last, 2]], CartFixture::lines($this->connection, $cart));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame([1, 0], OrderingFixture::stock($this->connection, $last));
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function wrongTotals(): iterable
    {
        yield 'one grosz lower' => [24_599];
        yield 'zero' => [0];
        yield 'one grosz higher' => [24_601];
        yield 'much higher' => [10_000_000];
    }

    #[DataProvider('wrongTotals')]
    public function testAnotherExpectedTotalIsAConflictWithoutOrderOrReservation(int $expected): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 2]]);

        $this->assertConflict('order-total-changed', 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', $this->place(self::PATH, $this->body($cart, $expected)));

        self::assertSame([[$shoes, 2]], CartFixture::lines($this->connection, $cart));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    public function testLinesQuantitiesAndPricesInTheBodyAreIgnored(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 2]]);

        $order = $this->place(self::PATH, [
            ...$this->body($cart, 24_600),
            'lines' => [['variantId' => $shoes, 'quantity' => 9, 'unitGross' => 1, 'lineGross' => 9]],
            'totals' => ['net' => 1, 'vat' => 0, 'gross' => 1, 'currency' => 'PLN'],
            'unitGross' => 1,
            'price' => 1,
            'quantity' => 9,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($order['lines']);
        self::assertSame([['variantId' => $shoes, 'productName' => 'Buty Puls', 'variantLabel' => '', 'quantity' => 2, 'unitGross' => 12_300, 'lineGross' => 24_600]], $order['lines']);
        self::assertSame(['net' => 20_000, 'vat' => 4_600, 'gross' => 24_600, 'currency' => 'PLN'], $order['totals']);
        self::assertIsString($order['orderId']);
        self::assertSame([$shoes => 2], OrderingFixture::reservation($this->connection, $order['orderId']));
    }

    public function testAValidCustomerTokenDoesNotChangeTheGuestEndpoint(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $order = $this->place(self::PATH, $this->body($cart, 12_300), token: TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::EMAIL, $order['email']);
        self::assertSame(1, OrderingFixture::count($this->connection, "SELECT COUNT(*) FROM ordering_order WHERE id = ? AND owner_type = 'guest' AND customer_id IS NULL", [$order['orderId']]));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingOrMalformedKeys(): iterable
    {
        yield 'no header' => [null];
        yield 'an empty key' => [''];
        yield 'a key with a space' => ['key 1'];
        yield 'a key with a slash' => ['key/1'];
        yield 'a key of 101 characters' => [str_repeat('k', 101)];
        yield 'a key with a letter outside ASCII' => ['klucz-ż'];
    }

    #[DataProvider('missingOrMalformedKeys')]
    public function testAMissingOrMalformedKeyIsABadRequestAndPlacesNothing(?string $key): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $problem = $this->place(self::PATH, $this->body($cart, 12_300), key: $key);

        $this->assertProblem(400, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertSame(['Idempotency-Key'], array_column($problem['violations'], 'propertyPath'));
        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame(0, $this->orders());
    }

    public function testAKeyOfOneHundredAllowedCharactersIsAccepted(): void
    {
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(12_300, 10), 1]]);

        $this->place(self::PATH, $this->body($cart, 12_300), key: 'Az09._:-'.str_repeat('k', 92));

        self::assertResponseStatusCodeSame(201);
    }

    public function testBadJsonIs400AndAnotherContentTypeIs415(): void
    {
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(12_300, 10), 1]]);

        $this->assertProblem(400, null, $this->place(self::PATH, '{"cartId": '));
        $this->assertProblem(415, null, $this->place(self::PATH, 'cartId='.$cart, contentType: 'application/x-www-form-urlencoded'));
        $this->assertProblem(415, null, $this->place(self::PATH, (string) json_encode($this->body($cart, 12_300)), contentType: 'text/plain'));
        self::assertSame(0, $this->orders());
    }

    /**
     * @param array<string, mixed>        $changes
     * @param list<array{string, string}> $violations
     */
    #[DataProvider('invalidGuestBodies')]
    public function testAnInvalidBodyGivesPolishViolationsAndPlacesNothing(array $changes, array $violations): void
    {
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(12_300, 10), 1]]);
        $body = self::changed($this->body($cart, 12_300), $changes);

        $this->assertViolations($violations, $this->place(self::PATH, [] === $body ? '{}' : $body));

        self::assertSame(0, $this->orders());
    }

    public function testAnEmptyBodyGivesTheViolationsOfEveryField(): void
    {
        $problem = $this->place(self::PATH, '');

        $this->assertProblem(422, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertSame(['cartId', 'email', 'fullName', 'phone', 'street', 'postalCode', 'city', 'expectedTotalGross'], array_column($problem['violations'], 'propertyPath'));
    }

    public function testTheLongestValidValuesAreAccepted(): void
    {
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(12_300, 10), 1]]);
        $email = str_repeat('a', 242).'@example.com';

        $order = $this->place(self::PATH, [
            ...$this->body($cart, 12_300),
            'email' => ' '.$email.' ',
            'fullName' => str_repeat('ż', 100),
            'phone' => '+48 600 100 200',
            'street' => str_repeat('ł', 100),
            'city' => str_repeat('ś', 60),
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($email, $order['email']);
        self::assertSame('+48600100200', $order['phone']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'garbage' => ['abc'];
        yield 'staff token' => ['staff'];
        yield 'expired customer token' => ['expired'];
    }

    #[DataProvider('refusedTokens')]
    public function testAnInvalidTokenIs401AndPlacesNothing(string $token): void
    {
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(12_300, 10), 1]]);
        $token = match ($token) {
            'staff' => TestTokenFactory::staff(['admin']),
            'expired' => TestTokenFactory::customer(['iat' => time() - 600, 'exp' => time() - 1]),
            default => $token,
        };

        $this->assertProblem(401, 'Authentication is required.', $this->place(self::PATH, $this->body($cart, 12_300), token: $token));

        self::assertSame('Bearer', $this->response()->headers->get('WWW-Authenticate'));
        self::assertSame(0, $this->orders());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $cartId, int $expectedTotalGross): array
    {
        return ['cartId' => $cartId, 'email' => self::EMAIL, ...self::details($expectedTotalGross)];
    }
}
