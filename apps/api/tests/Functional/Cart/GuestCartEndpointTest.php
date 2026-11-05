<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cart;

use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The guest cart endpoints (/api/v1/guest-carts…), without a token.
 */
final class GuestCartEndpointTest extends WebTestCase
{
    use CartEndpointTestTrait;
    use CartRequestViolationCases;
    use Factories;

    public function testCreateAnswers201WithAnEmptyCartAndItsLocation(): void
    {
        $cart = $this->request('POST', '/api/v1/guest-carts');

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($cart['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $cart['id'], 'a random UUID v4');
        self::assertSame(['id' => $cart['id'], 'lines' => [], 'totals' => ['gross' => 0, 'currency' => 'PLN', 'itemCount' => 0], 'hasExcludedLines' => false], $cart);
        self::assertSame('/api/v1/guest-carts/'.$cart['id'], $this->response()->headers->get('Location'));
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $cart);
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND owner_type = 'guest' AND status = 'open' AND expires_at > now() + interval '29 days'", [$cart['id']]));
    }

    public function testAGuestAddsChangesAndRemovesItems(): void
    {
        $shoes = $this->variant(19_999, 10, 'Buty Puls', ['Biegaczka']);
        $cap = $this->variant(4_990, 10, 'Czapka');
        $cart = $this->guestCart();

        $added = $this->request('POST', $cart.'/items', json_encode(['variantId' => $shoes, 'quantity' => 2], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $added);
        self::assertIsArray($added['lines']);
        self::assertCount(1, $added['lines']);
        $line = $added['lines'][0];
        self::assertIsArray($line);
        self::assertSame($shoes, $line['variantId']);
        self::assertIsArray($line['product']);
        self::assertSame('Buty Puls', $line['product']['name']);
        self::assertSame($this->productOf[$shoes], $line['product']['productId']);
        self::assertNotSame($shoes, $line['product']['productId']);
        self::assertSame('', $line['product']['variantLabel'], 'the default variant has an empty label');
        self::assertIsArray($line['product']['image']);
        self::assertSame('listing', $line['product']['image']['size']);
        self::assertSame(['quantity' => 2, 'unitPrice' => ['gross' => 19_999, 'currency' => 'PLN'], 'lineTotal' => ['gross' => 39_998, 'currency' => 'PLN'], 'state' => 'purchasable'], array_intersect_key($line, array_flip(['quantity', 'unitPrice', 'lineTotal', 'state'])));

        $this->request('POST', $cart.'/items', json_encode(['variantId' => $cap, 'quantity' => 1], \JSON_THROW_ON_ERROR));
        $again = $this->request('POST', $cart.'/items', json_encode(['variantId' => strtoupper($shoes), 'quantity' => 1], \JSON_THROW_ON_ERROR));
        self::assertSame([[$shoes, 3], [$cap, 1]], self::quantities($again));
        self::assertSame(['gross' => 3 * 19_999 + 4_990, 'currency' => 'PLN', 'itemCount' => 4], $again['totals']);

        $changed = $this->request('PUT', $cart.'/items/'.$shoes, '{"quantity": 1}');
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame([[$shoes, 1], [$cap, 1]], self::quantities($changed));

        $removed = $this->request('DELETE', $cart.'/items/'.$shoes);
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame([[$cap, 1]], self::quantities($removed));

        $missing = $this->request('DELETE', $cart.'/items/'.$shoes);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($removed, $missing);

        $read = $this->request('GET', $cart);
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame($removed, $read);

        $summary = $this->request('GET', $cart.'/summary');
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame(['itemCount' => 1], $summary);
        self::assertMatchesSchema('CartSummary', $summary);
    }

    public function testTheLineStatesAndTotalsComeFromTheApi(): void
    {
        $cart = $this->guestCart();
        $id = $this->cartId($cart);
        $purchasable = $this->variant(1_000, 10);
        $noPrice = $this->variant(null, 10);
        $outOfStock = $this->variant(2_000, 0);
        $short = $this->variant(3_000, 1);
        $unknown = (string) Uuid::v7();
        foreach ([[$unknown, 1], [$noPrice, 1], [$outOfStock, 1], [$short, 2], [$purchasable, 3]] as $index => [$variant, $quantity]) {
            CartFixture::line($this->connection, $id, $variant, $quantity, new \DateTimeImmutable(\sprintf('-%d minutes', 10 - $index)));
        }

        $view = $this->request('GET', $cart);

        self::assertIsArray($view['lines']);
        self::assertSame(['unavailable_product', 'no_price', 'out_of_stock', 'insufficient_stock', 'purchasable'], array_column($view['lines'], 'state'));
        self::assertSame([null, null, null, null, ['gross' => 3_000, 'currency' => 'PLN']], array_column($view['lines'], 'lineTotal'));
        self::assertIsArray($view['lines'][0]);
        self::assertArrayHasKey('product', $view['lines'][0]);
        self::assertNull($view['lines'][0]['product']);
        self::assertSame(['gross' => 3_000, 'currency' => 'PLN', 'itemCount' => 8], $view['totals']);
        self::assertTrue($view['hasExcludedLines']);
        self::assertStringNotContainsString('"available"', (string) $this->response()->getContent());
    }

    public function testTheLimitsAndTheOffer(): void
    {
        $cart = $this->guestCart();
        $many = $this->variant(1_000, 500);
        $this->add($cart, $many, 99);
        $this->assertProblem(409, 'Można dodać najwyżej 99 sztuk jednej pozycji.', $this->add($cart, $many, 1));

        $full = $this->guestCart();
        $id = $this->cartId($full);
        for ($i = 1; $i <= 50; ++$i) {
            CartFixture::line($this->connection, $id, \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1);
        }
        $this->assertProblem(409, 'Koszyk może zawierać najwyżej 50 różnych pozycji.', $this->add($full, $many, 1));

        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->add($cart, $this->variant(null, 10), 1));
        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->add($cart, $this->variant(1_000, 0), 1));
        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->add($cart, $this->variant(1_000, null), 1));

        $three = $this->variant(1_000, 3);
        $problem = $this->add($cart, $three, 4);
        $this->assertProblem(409, 'Nie mamy tylu sztuk tego produktu.', $problem);
        self::assertIsString($problem['detail'] ?? null);
        self::assertStringNotContainsString('3', $problem['detail']);
        $this->add($cart, $three, 3);
        $this->assertProblem(409, 'Nie mamy tylu sztuk tego produktu.', $this->request('PUT', $cart.'/items/'.$three, '{"quantity": 4}'));

        // A decrease is always possible, also once the product cannot be bought any more.
        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE variant_id = ?', [$three]);
        $this->connection->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id = ?', [$three]);
        $decreased = $this->request('PUT', $cart.'/items/'.$three, '{"quantity": 2}');
        self::assertResponseStatusCodeSame(200);
        self::assertContains([$three, 2], self::quantities($decreased));
        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->request('PUT', $cart.'/items/'.$three, '{"quantity": 3}'));

        self::assertSame([[$many, 99], [$three, 2]], CartFixture::lines($this->connection, $this->cartId($cart)));
    }

    /**
     * Two sizes of one product are two lines with their labels; the limit of 99 units applies to each line on its own.
     */
    public function testTwoVariantsOfOneProductAreTwoLines(): void
    {
        [$size42, $size43] = $this->variants([[30_000, 200, ['czarne', '42']], [31_000, 200, ['czarne', '43']]], ['Kolor', 'Rozmiar'], 'Buty Asfalt');
        $cart = $this->guestCart();

        $this->add($cart, $size42, 99);
        $view = $this->add($cart, $size43, 99);

        self::assertResponseStatusCodeSame(200);
        self::assertMatchesSchema('CartView', $view);
        self::assertSame([[$size42, 99], [$size43, 99]], self::quantities($view));
        self::assertIsArray($view['lines']);
        self::assertSame(
            [[$this->productOf[$size42], 'Buty Asfalt', 'czarne, 42'], [$this->productOf[$size42], 'Buty Asfalt', 'czarne, 43']],
            array_map(static fn (mixed $line): array => \is_array($line) && \is_array($line['product']) ? [$line['product']['productId'], $line['product']['name'], $line['product']['variantLabel']] : [], $view['lines']),
        );
        $this->assertProblem(409, 'Można dodać najwyżej 99 sztuk jednej pozycji.', $this->add($cart, $size43, 1));
    }

    public function testTheIdOfAProductIsNoVariantToAdd(): void
    {
        $variant = $this->variant();
        $cart = $this->guestCart();

        $this->assertProblem(404, 'Produkt nie jest już dostępny.', $this->add($cart, $this->productOf[$variant], 1));
        self::assertSame([], CartFixture::lines($this->connection, $this->cartId($cart)));
    }

    public function testAnUnknownVariantAndAMissingLineAreNotFound(): void
    {
        $cart = $this->guestCart();

        $this->assertProblem(404, 'Produkt nie jest już dostępny.', $this->add($cart, (string) Uuid::v7(), 1));
        $this->assertProblem(404, 'Tej pozycji nie ma w koszyku.', $this->request('PUT', $cart.'/items/'.Uuid::v7(), '{"quantity": 1}'));
    }

    public function testAnIncreaseOfALineWhoseProductCatalogForgotIsNotFoundAndADecreaseWorks(): void
    {
        $cart = $this->guestCart();
        $forgotten = (string) Uuid::v7();
        CartFixture::line($this->connection, $this->cartId($cart), $forgotten, 3);

        $this->assertProblem(404, 'Produkt nie jest już dostępny.', $this->request('PUT', $cart.'/items/'.$forgotten, '{"quantity": 4}'));
        self::assertSame([[$forgotten, 3]], CartFixture::lines($this->connection, $this->cartId($cart)));

        $decreased = $this->request('PUT', $cart.'/items/'.$forgotten, '{"quantity": 2}');
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame([[$forgotten, 2]], self::quantities($decreased));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function operations(): iterable
    {
        $variant = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
        yield 'get' => ['GET', '', null];
        yield 'summary' => ['GET', '/summary', null];
        yield 'add' => ['POST', '/items', '{"variantId": "'.$variant.'", "quantity": 1}'];
        yield 'change' => ['PUT', '/items/'.$variant, '{"quantity": 1}'];
        yield 'remove' => ['DELETE', '/items/'.$variant, null];
    }

    #[DataProvider('operations')]
    public function testAnUnknownExpiredMergedOrCustomerCartIsNotFoundWithoutChange(string $method, string $suffix, ?string $body): void
    {
        $variant = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
        $carts = [
            'unknown' => (string) Uuid::v4(),
            'expired' => CartFixture::cart($this->connection, expiresAt: '-1 second', lines: [[$variant, 2]]),
            'merged' => CartFixture::cart($this->connection, status: 'merged'),
            'customer' => CartFixture::cart($this->connection, customerId: 'kc-sub-x', lines: [[$variant, 2]]),
        ];

        foreach ($carts as $kind => $id) {
            $this->assertProblem(404, 'Koszyk nie jest już dostępny.', $this->request($method, '/api/v1/guest-carts/'.$id.$suffix, $body));
            self::assertSame('about:blank', $this->json()['type'] ?? null, $kind);
        }
        self::assertSame([[$variant, 2]], CartFixture::lines($this->connection, $carts['expired']));
        self::assertSame([[$variant, 2]], CartFixture::lines($this->connection, $carts['customer']));
    }

    #[DataProvider('operations')]
    public function testAnIdThatIsNotAUuidIsNotFound(string $method, string $suffix, ?string $body): void
    {
        $this->assertProblem(404, null, $this->request($method, '/api/v1/guest-carts/not-a-uuid'.$suffix, $body));
    }

    public function testAVariantIdInThePathThatIsNotAUuidIsNotFound(): void
    {
        $cart = $this->guestCart();

        $this->assertProblem(404, null, $this->request('PUT', $cart.'/items/not-a-uuid', '{"quantity": 1}'));
        $this->assertProblem(404, null, $this->request('DELETE', $cart.'/items/not-a-uuid'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAdditions(): iterable
    {
        $variant = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
        yield 'quantity 0' => ['{"variantId": "'.$variant.'", "quantity": 0}', 'quantity'];
        yield 'quantity 100' => ['{"variantId": "'.$variant.'", "quantity": 100}', 'quantity'];
        yield 'quantity in a string' => ['{"variantId": "'.$variant.'", "quantity": "1"}', 'quantity'];
        yield 'fraction' => ['{"variantId": "'.$variant.'", "quantity": 1.5}', 'quantity'];
        yield 'whole fraction' => ['{"variantId": "'.$variant.'", "quantity": 2.0}', 'quantity'];
        yield 'no quantity' => ['{"variantId": "'.$variant.'"}', 'quantity'];
        yield 'null quantity' => ['{"variantId": "'.$variant.'", "quantity": null}', 'quantity'];
        yield 'no variant' => ['{"quantity": 1}', 'variantId'];
        yield 'variant not a uuid' => ['{"variantId": "abc", "quantity": 1}', 'variantId'];
        yield 'variant a number' => ['{"variantId": 5, "quantity": 1}', 'variantId'];
        yield 'empty object' => ['{}', 'quantity'];
    }

    #[DataProvider('invalidAdditions')]
    public function testAnInvalidAdditionIs422(string $body, string $propertyPath): void
    {
        $cart = $this->guestCart();

        $this->assertViolation($propertyPath, $this->request('POST', $cart.'/items', $body));
        self::assertSame([], CartFixture::lines($this->connection, $this->cartId($cart)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'quantity 0' => ['{"quantity": 0}'];
        yield 'quantity 100' => ['{"quantity": 100}'];
        yield 'in a string' => ['{"quantity": "2"}'];
        yield 'fraction' => ['{"quantity": 1.5}'];
        yield 'missing' => ['{}'];
    }

    #[DataProvider('invalidQuantities')]
    public function testAnInvalidQuantityChangeIs422(string $body): void
    {
        $cart = $this->guestCart();
        $variant = $this->variant();
        $this->add($cart, $variant, 1);

        $this->assertViolation('quantity', $this->request('PUT', $cart.'/items/'.$variant, $body));
    }

    /**
     * @param list<array{string, string}> $violations
     */
    #[DataProvider('invalidAddItemBodies')]
    public function testAnInvalidAdditionGivesPolishViolations(string $body, array $violations): void
    {
        $cart = $this->guestCart();

        $this->assertViolations($violations, $this->request('POST', $cart.'/items', $body));
        self::assertSame([], CartFixture::lines($this->connection, $this->cartId($cart)));
    }

    /**
     * @param list<array{string, string}> $violations
     */
    #[DataProvider('invalidChangeQuantityBodies')]
    public function testAnInvalidQuantityChangeGivesPolishViolations(string $body, array $violations): void
    {
        $cart = $this->guestCart();
        $variant = $this->variant();
        $this->add($cart, $variant, 1);

        $this->assertViolations($violations, $this->request('PUT', $cart.'/items/'.$variant, $body));
        self::assertSame([[$variant, 1]], CartFixture::lines($this->connection, $this->cartId($cart)));
    }

    public function testBadJsonIs400AndAnotherContentTypeIs415(): void
    {
        $cart = $this->guestCart();
        $variant = $this->variant();
        $this->add($cart, $variant, 1);

        $this->assertProblem(400, null, $this->request('POST', $cart.'/items', '{"variantId": '));
        $this->assertProblem(400, null, $this->request('PUT', $cart.'/items/'.$variant, '{"quantity": '));
        $this->assertProblem(415, null, $this->request('POST', $cart.'/items', 'variantId=1&quantity=1', contentType: 'application/x-www-form-urlencoded'));
        $this->assertProblem(415, null, $this->request('PUT', $cart.'/items/'.$variant, 'quantity=1', contentType: 'text/plain'));
    }

    public function testAnInvalidTokenOnAGuestCartIs401(): void
    {
        $this->assertProblem(401, null, $this->request('POST', '/api/v1/guest-carts', token: 'abc'));
        $this->assertProblem(401, null, $this->request('POST', '/api/v1/guest-carts', token: TestTokenFactory::staff(['admin'])));
    }

    public function testAnExpiredGuestCartWasExtendedByItsLastChange(): void
    {
        $cart = $this->guestCart();
        $id = $this->cartId($cart);
        $this->connection->executeStatement("UPDATE cart_cart SET expires_at = now() + interval '1 hour' WHERE id = ?", [$id]);

        $this->add($cart, $this->variant(), 1);

        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND expires_at > now() + interval '29 days'", [$id]));
    }

    private function guestCart(): string
    {
        $cart = $this->request('POST', '/api/v1/guest-carts');
        self::assertIsString($cart['id']);

        return '/api/v1/guest-carts/'.$cart['id'];
    }

    private function cartId(string $path): string
    {
        return substr($path, \strlen('/api/v1/guest-carts/'));
    }

    /**
     * @return array<mixed>
     */
    private function add(string $cart, string $variantId, int $quantity): array
    {
        return $this->request('POST', $cart.'/items', json_encode(['variantId' => $variantId, 'quantity' => $quantity], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->response()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $view
     *
     * @return list<array{mixed, mixed}>
     */
    private static function quantities(array $view): array
    {
        self::assertIsArray($view['lines']);

        return array_values(array_map(static fn (mixed $line): array => \is_array($line) ? [$line['variantId'], $line['quantity']] : [null, null], $view['lines']));
    }
}
