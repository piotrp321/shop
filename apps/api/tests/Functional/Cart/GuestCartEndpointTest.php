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
        $shoes = $this->product(19_999, 10, 'Buty Puls', ['Biegaczka']);
        $cap = $this->product(4_990, 10, 'Czapka');
        $cart = $this->guestCart();

        $added = $this->request('POST', $cart.'/items', json_encode(['productId' => $shoes, 'quantity' => 2], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $added);
        self::assertIsArray($added['lines']);
        self::assertCount(1, $added['lines']);
        $line = $added['lines'][0];
        self::assertIsArray($line);
        self::assertSame($shoes, $line['productId']);
        self::assertIsArray($line['product']);
        self::assertSame('Buty Puls', $line['product']['name']);
        self::assertIsArray($line['product']['image']);
        self::assertSame('listing', $line['product']['image']['size']);
        self::assertSame(['quantity' => 2, 'unitPrice' => ['gross' => 19_999, 'currency' => 'PLN'], 'lineTotal' => ['gross' => 39_998, 'currency' => 'PLN'], 'state' => 'purchasable'], array_intersect_key($line, array_flip(['quantity', 'unitPrice', 'lineTotal', 'state'])));

        $this->request('POST', $cart.'/items', json_encode(['productId' => $cap, 'quantity' => 1], \JSON_THROW_ON_ERROR));
        $again = $this->request('POST', $cart.'/items', json_encode(['productId' => strtoupper($shoes), 'quantity' => 1], \JSON_THROW_ON_ERROR));
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
        $purchasable = $this->product(1_000, 10);
        $noPrice = $this->product(null, 10);
        $outOfStock = $this->product(2_000, 0);
        $short = $this->product(3_000, 1);
        $unknown = (string) Uuid::v7();
        foreach ([[$unknown, 1], [$noPrice, 1], [$outOfStock, 1], [$short, 2], [$purchasable, 3]] as $index => [$product, $quantity]) {
            CartFixture::line($this->connection, $id, $product, $quantity, new \DateTimeImmutable(\sprintf('-%d minutes', 10 - $index)));
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
        $many = $this->product(1_000, 500);
        $this->add($cart, $many, 99);
        $this->assertProblem(409, 'Można dodać najwyżej 99 sztuk jednego produktu.', $this->add($cart, $many, 1));

        $full = $this->guestCart();
        $id = $this->cartId($full);
        for ($i = 1; $i <= 50; ++$i) {
            CartFixture::line($this->connection, $id, \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1);
        }
        $this->assertProblem(409, 'Koszyk może zawierać najwyżej 50 różnych produktów.', $this->add($full, $many, 1));

        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->add($cart, $this->product(null, 10), 1));
        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->add($cart, $this->product(1_000, 0), 1));
        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->add($cart, $this->product(1_000, null), 1));

        $three = $this->product(1_000, 3);
        $problem = $this->add($cart, $three, 4);
        $this->assertProblem(409, 'Nie mamy tylu sztuk tego produktu.', $problem);
        self::assertIsString($problem['detail'] ?? null);
        self::assertStringNotContainsString('3', $problem['detail']);
        $this->add($cart, $three, 3);
        $this->assertProblem(409, 'Nie mamy tylu sztuk tego produktu.', $this->request('PUT', $cart.'/items/'.$three, '{"quantity": 4}'));

        // A decrease is always possible, also once the product cannot be bought any more.
        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE product_id = ?', [$three]);
        $this->connection->executeStatement('DELETE FROM pricing_product_price WHERE product_id = ?', [$three]);
        $decreased = $this->request('PUT', $cart.'/items/'.$three, '{"quantity": 2}');
        self::assertResponseStatusCodeSame(200);
        self::assertContains([$three, 2], self::quantities($decreased));
        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->request('PUT', $cart.'/items/'.$three, '{"quantity": 3}'));

        self::assertSame([[$many, 99], [$three, 2]], CartFixture::lines($this->connection, $this->cartId($cart)));
    }

    public function testAnUnknownProductAndAMissingLineAreNotFound(): void
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
        $product = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
        yield 'get' => ['GET', '', null];
        yield 'summary' => ['GET', '/summary', null];
        yield 'add' => ['POST', '/items', '{"productId": "'.$product.'", "quantity": 1}'];
        yield 'change' => ['PUT', '/items/'.$product, '{"quantity": 1}'];
        yield 'remove' => ['DELETE', '/items/'.$product, null];
    }

    #[DataProvider('operations')]
    public function testAnUnknownExpiredMergedOrCustomerCartIsNotFoundWithoutChange(string $method, string $suffix, ?string $body): void
    {
        $product = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
        $carts = [
            'unknown' => (string) Uuid::v4(),
            'expired' => CartFixture::cart($this->connection, expiresAt: '-1 second', lines: [[$product, 2]]),
            'merged' => CartFixture::cart($this->connection, status: 'merged'),
            'customer' => CartFixture::cart($this->connection, customerId: 'kc-sub-x', lines: [[$product, 2]]),
        ];

        foreach ($carts as $kind => $id) {
            $this->assertProblem(404, 'Koszyk nie jest już dostępny.', $this->request($method, '/api/v1/guest-carts/'.$id.$suffix, $body));
            self::assertSame('about:blank', $this->json()['type'] ?? null, $kind);
        }
        self::assertSame([[$product, 2]], CartFixture::lines($this->connection, $carts['expired']));
        self::assertSame([[$product, 2]], CartFixture::lines($this->connection, $carts['customer']));
    }

    #[DataProvider('operations')]
    public function testAnIdThatIsNotAUuidIsNotFound(string $method, string $suffix, ?string $body): void
    {
        $this->assertProblem(404, null, $this->request($method, '/api/v1/guest-carts/not-a-uuid'.$suffix, $body));
    }

    public function testAProductIdInThePathThatIsNotAUuidIsNotFound(): void
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
        $product = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
        yield 'quantity 0' => ['{"productId": "'.$product.'", "quantity": 0}', 'quantity'];
        yield 'quantity 100' => ['{"productId": "'.$product.'", "quantity": 100}', 'quantity'];
        yield 'quantity in a string' => ['{"productId": "'.$product.'", "quantity": "1"}', 'quantity'];
        yield 'fraction' => ['{"productId": "'.$product.'", "quantity": 1.5}', 'quantity'];
        yield 'whole fraction' => ['{"productId": "'.$product.'", "quantity": 2.0}', 'quantity'];
        yield 'no quantity' => ['{"productId": "'.$product.'"}', 'quantity'];
        yield 'null quantity' => ['{"productId": "'.$product.'", "quantity": null}', 'quantity'];
        yield 'no product' => ['{"quantity": 1}', 'productId'];
        yield 'product not a uuid' => ['{"productId": "abc", "quantity": 1}', 'productId'];
        yield 'product a number' => ['{"productId": 5, "quantity": 1}', 'productId'];
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
        $product = $this->product();
        $this->add($cart, $product, 1);

        $this->assertViolation('quantity', $this->request('PUT', $cart.'/items/'.$product, $body));
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
        $product = $this->product();
        $this->add($cart, $product, 1);

        $this->assertViolations($violations, $this->request('PUT', $cart.'/items/'.$product, $body));
        self::assertSame([[$product, 1]], CartFixture::lines($this->connection, $this->cartId($cart)));
    }

    public function testBadJsonIs400AndAnotherContentTypeIs415(): void
    {
        $cart = $this->guestCart();
        $product = $this->product();
        $this->add($cart, $product, 1);

        $this->assertProblem(400, null, $this->request('POST', $cart.'/items', '{"productId": '));
        $this->assertProblem(400, null, $this->request('PUT', $cart.'/items/'.$product, '{"quantity": '));
        $this->assertProblem(415, null, $this->request('POST', $cart.'/items', 'productId=1&quantity=1', contentType: 'application/x-www-form-urlencoded'));
        $this->assertProblem(415, null, $this->request('PUT', $cart.'/items/'.$product, 'quantity=1', contentType: 'text/plain'));
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

        $this->add($cart, $this->product(), 1);

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
    private function add(string $cart, string $productId, int $quantity): array
    {
        return $this->request('POST', $cart.'/items', json_encode(['productId' => $productId, 'quantity' => $quantity], \JSON_THROW_ON_ERROR));
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

        return array_values(array_map(static fn (mixed $line): array => \is_array($line) ? [$line['productId'], $line['quantity']] : [null, null], $view['lines']));
    }
}
