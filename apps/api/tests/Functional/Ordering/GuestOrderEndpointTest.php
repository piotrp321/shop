<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\InputConformance\InputValues;
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

        $order = $this->place(self::PATH, $this->body($cart, 3 * 12_300 + 1_230, [[$size43, 2], [$map, 1], [$size42, 1]]));

        self::assertResponseStatusCodeSame(201);
        $this->assertNoStore();
        $this->assertOrderView($order);
        self::assertSame(self::EMAIL, $order['email']);
        self::assertSame(self::CONTACT, array_intersect_key($order, self::CONTACT));
        self::assertSame([
            ['variantId' => $size43, 'productName' => 'Buty Asfalt', 'variantLabel' => 'czarne, 43', 'quantity' => 2, 'unitGross' => 12_300, 'grossBeforeDiscount' => 24_600, 'discountGross' => 0, 'lineGross' => 24_600],
            ['variantId' => $map, 'productName' => 'Mapa', 'variantLabel' => '', 'quantity' => 1, 'unitGross' => 1_230, 'grossBeforeDiscount' => 1_230, 'discountGross' => 0, 'lineGross' => 1_230],
            ['variantId' => $size42, 'productName' => 'Buty Asfalt', 'variantLabel' => 'czarne, 42', 'quantity' => 1, 'unitGross' => 12_300, 'grossBeforeDiscount' => 12_300, 'discountGross' => 0, 'lineGross' => 12_300],
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

    /**
     * The order copies the effective price of Pricing: in an active promotion the promotional gross with its net and
     * VAT at the rate of the catalog price, without code of Ordering for it.
     */
    public function testAVariantInAPromotionIsOrderedAtItsPromotionalPrice(): void
    {
        $promoted = $this->variant(12_300, 10, 'Buty Asfalt');
        $map = $this->variant(1_230, 5, 'Mapa');
        $this->promote(20, [$promoted]);
        $cart = CartFixture::cart($this->connection, lines: [[$promoted, 2], [$map, 1]]);

        // 12 300 − floor(12 300 × 20 / 100) = 9 840: net 8 000 and VAT 1 840 at 23%.
        $order = $this->place(self::PATH, $this->body($cart, 2 * 9_840 + 1_230, [[$promoted, 2], [$map, 1]]));

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($order['lines']);
        self::assertSame([[9_840, 19_680], [1_230, 1_230]], array_map(static fn (mixed $line): array => \is_array($line) ? [$line['unitGross'], $line['lineGross']] : [], $order['lines']));
        self::assertSame(['net' => 17_000, 'vat' => 3_910, 'gross' => 20_910, 'currency' => 'PLN'], $order['totals']);
    }

    public function testARepeatAnswers200WithTheSameOrderAndNoSecondReservation(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 2]]);
        $first = $this->place(self::PATH, $this->body($cart, 24_600, [[$shoes, 2]]));
        self::assertResponseStatusCodeSame(201);
        CartFixture::line($this->connection, $cart, $shoes, 3);

        $repeated = $this->place(self::PATH, $this->body($cart, 24_600, [[$shoes, 2]]));

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
        $first = $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]]));
        self::assertResponseStatusCodeSame(201);

        $this->connection->executeStatement("UPDATE cart_cart SET status = 'merged', merged_into = ?, merged_at = now() WHERE id = ?", [(string) Uuid::v4(), $cart]);
        self::assertSame($first, $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]])));
        self::assertResponseStatusCodeSame(200);

        $this->connection->executeStatement("UPDATE cart_cart SET status = 'open', merged_into = NULL, merged_at = NULL, expires_at = now() - interval '1 second' WHERE id = ?", [$cart]);
        self::assertSame($first, $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]])));
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
        $earlier = $this->place(self::PATH, $this->body($first, 12_300, [[$shoes, 1]]));
        self::assertResponseStatusCodeSame(201);

        $this->assertKeyReused($this->place(self::PATH, $this->body($other, 12_300, [[$shoes, 1]])), $earlier);

        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $other));
        self::assertSame(1, $this->orders());
        self::assertSame(1, $this->reservations());
    }

    public function testTheKeyWithOtherContentIsAConflictWithoutTheDataOfItsOrder(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $earlier = $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]]));
        CartFixture::line($this->connection, $cart, $this->variant(1_230, 5), 1);

        foreach ([['fullName' => 'Jan Nowak'], ['email' => 'jan@example.com'], ['city' => 'Kraków'], ['expectedTotalGross' => 1_230], ['expectedLines' => OrderingFixture::expected([[$shoes, 2]])]] as $change) {
            $this->assertKeyReused($this->place(self::PATH, [...$this->body($cart, 12_300, [[$shoes, 1]]), ...$change]), $earlier);
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
            $this->assertProblem(404, 'Koszyk nie jest już dostępny.', $this->place(self::PATH, $this->body($cartId, 12_300, [[$shoes, 1]]), key: 'key-'.$kind));
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
            $this->assertProblem(404, 'Koszyk nie jest już dostępny.', $this->place(self::PATH, $this->body($cartId, 12_300, [[(string) Uuid::v7(), 1]]), key: 'key-'.$cartId));
        }
        self::assertSame(0, $this->orders());
    }

    public function testTheIdOfTheCartInUpperCaseIsTheSameCart(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $order = $this->place(self::PATH, $this->body(strtoupper($cart), 12_300, [[$shoes, 1]]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame($order, $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]])), 'a repeat with the id in lower case');
        self::assertResponseStatusCodeSame(200);
    }

    public function testAnEmptyCartIsAConflict(): void
    {
        $cart = CartFixture::cart($this->connection);

        $this->assertConflict('cart-empty', 'Koszyk jest pusty.', $this->place(self::PATH, $this->body($cart, 0, [[(string) Uuid::v7(), 1]])));
        self::assertSame(0, $this->orders());
    }

    public function testALineWithoutAPriceUnknownToCatalogOrOfADraftIsAConflict(): void
    {
        $shoes = $this->variant(12_300, 10);
        foreach (['no price' => $this->variant(null, 10), 'unknown to Catalog' => (string) Uuid::v7(), 'of a draft' => $this->draftVariant(12_300, 10)] as $kind => $variant) {
            $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1], [$variant, 1]]);

            $this->assertConflict('cart-not-orderable', 'Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.', $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1], [$variant, 1]]), key: 'key-'.$cart));

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

        $this->assertConflict('insufficient-stock-for-order', 'Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.', $this->place(self::PATH, $this->body($cart, 2 * 12_300 + 2 * 1_230, [[$shoes, 2], [$last, 2]])));

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

        $this->assertConflict('order-total-changed', 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', $this->place(self::PATH, $this->body($cart, $expected, [[$shoes, 2]])));

        self::assertSame([[$shoes, 2]], CartFixture::lines($this->connection, $cart));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $shoes));
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
    }

    /**
     * R1 of CART-ORDER-CONSISTENCY and its kin: the cart on the server is not the one the checkout showed, also at the
     * same total. Nothing is ordered, reserved or changed; the problem has only its type and detail.
     */
    public function testAnotherContentOfTheCartIsAConflictWithoutOrderReservationOrChange(): void
    {
        $a = $this->variant(12_300, 10);
        $b = $this->variant(12_300, 10);
        $c = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$a, 1], [$b, 2]]);

        foreach ([
            'the quantities swapped at the same total' => [[$a, 2], [$b, 1]],
            'another quantity' => [[$a, 1], [$b, 1]],
            'a line missing' => [[$b, 2]],
            'an extra line' => [[$a, 1], [$b, 2], [$c, 1]],
            'another variant' => [[$a, 1], [$c, 2]],
        ] as $kind => $lines) {
            $problem = $this->place(self::PATH, $this->body($cart, 3 * 12_300, $lines), key: 'key-'.str_replace(' ', '-', $kind));

            $this->assertConflict('cart-changed', 'Zawartość koszyka się zmieniła. Sprawdź podsumowanie.', $problem);
            self::assertSame(['type', 'title', 'status', 'detail'], array_keys($problem), $kind);
        }

        self::assertSame([[$a, 1], [$b, 2]], CartFixture::lines($this->connection, $cart));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $a));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection, $b));
        self::assertSame(0, $this->orders());
        self::assertSame(0, $this->reservations());
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim'));
    }

    public function testTheListInAnotherOrderAndLetterCaseIsTheContentOfTheCart(): void
    {
        $a = $this->variant(12_300, 10);
        $b = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$a, 1], [$b, 2]]);
        $notations = iterator_to_array(InputValues::equivalentUuidForms($b));

        $order = $this->place(self::PATH, $this->body($cart, 3 * 12_300, [[$notations['upper case'][0], 2], [$a, 1]]));

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($order['orderId']);
        $reserved = [$a => 1, $b => 2];
        ksort($reserved);
        self::assertSame($reserved, OrderingFixture::reservation($this->connection, $order['orderId']));
        self::assertSame($order, $this->place(self::PATH, $this->body($cart, 3 * 12_300, [[$a, 1], [$b, 2]])), 'the same list in its canonical form is a repeat');
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * A variant id of the format of a UUID outside the versions is a valid line of the list: no violation, but a cart
     * without it.
     */
    public function testAVariantIdOutsideTheVersionsIsAnotherContentNotAViolation(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        foreach (InputValues::uuidsOutsideTheVersions() as $label => [$uuid]) {
            $this->assertConflict('cart-changed', 'Zawartość koszyka się zmieniła. Sprawdź podsumowanie.', $this->place(self::PATH, $this->body($cart, 12_300, [[$uuid, 1]]), key: 'key-'.str_replace(' ', '-', $label)));
        }
        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame(0, $this->orders());
    }

    public function testAListOfFiftyLinesOfNinetyNineUnitsIsNoViolation(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $lines = [];
        for ($i = 1; $i <= 50; ++$i) {
            $lines[] = [\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 99];
        }

        $this->assertConflict('cart-changed', 'Zawartość koszyka się zmieniła. Sprawdź podsumowanie.', $this->place(self::PATH, $this->body($cart, 12_300, $lines)));
    }

    public function testAnEmptyCartIsEmptyWhateverTheList(): void
    {
        $cart = CartFixture::cart($this->connection);

        $this->assertConflict('cart-empty', 'Koszyk jest pusty.', $this->place(self::PATH, $this->body($cart, 12_300, [[$this->variant(12_300, 10), 1]])));
    }

    public function testLinesQuantitiesAndPricesInTheBodyAreIgnored(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 2]]);

        $order = $this->place(self::PATH, [
            ...$this->body($cart, 24_600, [[$shoes, 2]]),
            'lines' => [['variantId' => $shoes, 'quantity' => 9, 'unitGross' => 1, 'lineGross' => 9]],
            'totals' => ['net' => 1, 'vat' => 0, 'gross' => 1, 'currency' => 'PLN'],
            'unitGross' => 1,
            'price' => 1,
            'quantity' => 9,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($order['lines']);
        self::assertSame([['variantId' => $shoes, 'productName' => 'Buty Puls', 'variantLabel' => '', 'quantity' => 2, 'unitGross' => 12_300, 'grossBeforeDiscount' => 24_600, 'discountGross' => 0, 'lineGross' => 24_600]], $order['lines']);
        self::assertSame(['net' => 20_000, 'vat' => 4_600, 'gross' => 24_600, 'currency' => 'PLN'], $order['totals']);
        self::assertIsString($order['orderId']);
        self::assertSame([$shoes => 2], OrderingFixture::reservation($this->connection, $order['orderId']));
    }

    public function testAValidCustomerTokenDoesNotChangeTheGuestEndpoint(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $order = $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]]), token: TestTokenFactory::customer());

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

        $problem = $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]]), key: $key);

        $this->assertProblem(400, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertSame(['Idempotency-Key'], array_column($problem['violations'], 'propertyPath'));
        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart));
        self::assertSame(0, $this->orders());
    }

    public function testAKeyOfOneHundredAllowedCharactersIsAccepted(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]]), key: 'Az09._:-'.str_repeat('k', 92));

        self::assertResponseStatusCodeSame(201);
    }

    public function testBadJsonIs400AndAnotherContentTypeIs415(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $this->assertProblem(400, null, $this->place(self::PATH, '{"cartId": '));
        $this->assertProblem(415, null, $this->place(self::PATH, 'cartId='.$cart, contentType: 'application/x-www-form-urlencoded'));
        $this->assertProblem(415, null, $this->place(self::PATH, (string) json_encode($this->body($cart, 12_300, [[$shoes, 1]])), contentType: 'text/plain'));
        self::assertSame(0, $this->orders());
    }

    /**
     * @param array<string, mixed>        $changes
     * @param list<array{string, string}> $violations
     */
    #[DataProvider('invalidGuestBodies')]
    public function testAnInvalidBodyGivesPolishViolationsAndPlacesNothing(array $changes, array $violations): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $body = self::changed($this->body($cart, 12_300, [[$shoes, 1]]), $changes);

        $this->assertViolations($violations, $this->place(self::PATH, [] === $body ? '{}' : $body));

        self::assertSame(0, $this->orders());
    }

    /**
     * Fields with a format (a valid address, 9 digits, `NN-NNN`, docs/features/checkout/spec.md P4), so a value a reader
     * sees as empty is no valid value of the field, whichever of its messages it gets.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function formattedFieldsOfBlankUnicodeText(): iterable
    {
        foreach (['email', 'phone', 'postalCode'] as $field) {
            foreach (InputValues::blankUnicodeTexts() as $name => [$text]) {
                yield "$field of $name" => [$field, $text];
            }
        }
    }

    #[DataProvider('formattedFieldsOfBlankUnicodeText')]
    public function testAFormattedFieldOfUnicodeWhiteSpaceOnlyIsAViolationOfThatFieldAndPlacesNothing(string $field, string $text): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $problem = $this->place(self::PATH, [$field => $text] + $this->body($cart, 12_300, [[$shoes, 1]]));

        $this->assertProblem(422, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertSame($field, $violation['propertyPath'] ?? null);
        }
        self::assertNotSame([], $problem['violations']);
        self::assertSame(0, $this->orders());
    }

    public function testAnEmptyBodyGivesTheViolationsOfEveryField(): void
    {
        $problem = $this->place(self::PATH, '');

        $this->assertProblem(422, null, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertSame(['cartId', 'email', 'fullName', 'phone', 'street', 'postalCode', 'city', 'expectedTotalGross', 'deliveryMethodId', 'expectedLines'], array_column($problem['violations'], 'propertyPath'));
    }

    public function testTheLongestValidValuesAreAccepted(): void
    {
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $email = str_repeat('a', 242).'@example.com';

        $order = $this->place(self::PATH, [
            ...$this->body($cart, 12_300, [[$shoes, 1]]),
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
        $shoes = $this->variant(12_300, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);
        $token = match ($token) {
            'staff' => TestTokenFactory::staff(['admin']),
            'expired' => TestTokenFactory::customer(['iat' => time() - 600, 'exp' => time() - 1]),
            default => $token,
        };

        $this->assertProblem(401, 'Wymagane jest uwierzytelnienie.', $this->place(self::PATH, $this->body($cart, 12_300, [[$shoes, 1]]), token: $token));

        self::assertSame('Bearer', $this->response()->headers->get('WWW-Authenticate'));
        self::assertSame(0, $this->orders());
    }

    /**
     * @param list<array{string, int}> $expectedLines variant id and quantity the checkout showed
     *
     * @return array<string, mixed>
     */
    private function body(string $cartId, int $expectedTotalGross, array $expectedLines): array
    {
        return ['cartId' => $cartId, 'email' => self::EMAIL, ...self::details($expectedTotalGross, $expectedLines)];
    }
}
