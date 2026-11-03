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
 * The cart of the customer of the token (/api/v1/me/cart…).
 */
final class CustomerCartEndpointTest extends WebTestCase
{
    use CartEndpointTestTrait;
    use Factories;

    public function testACustomerWithoutACartReadsAnEmptyCartWithoutIdAndNothingIsCreated(): void
    {
        $view = $this->request('GET', '/api/v1/me/cart', token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame(['id' => null, 'lines' => [], 'totals' => ['gross' => 0, 'currency' => 'PLN', 'itemCount' => 0], 'hasExcludedLines' => false], $view);
        self::assertMatchesSchema('CartView', $view);

        self::assertSame(['itemCount' => 0], $this->request('GET', '/api/v1/me/cart/summary', token: TestTokenFactory::customer()));
        $this->assertNoStore();

        $removed = $this->request('DELETE', '/api/v1/me/cart/items/'.Uuid::v7(), token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(200);
        self::assertNull($removed['id']);

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]));
    }

    public function testTheFirstAdditionCreatesTheCartOfTheCustomerWhoThenChangesAndRemovesItems(): void
    {
        $token = TestTokenFactory::customer();
        $shoes = $this->product(19_999, 10);
        $cap = $this->product(4_990, 10);

        $added = $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $shoes, 'quantity' => 2], \JSON_THROW_ON_ERROR), $token);
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $added);
        $cartId = $this->connection->fetchOne("SELECT id FROM cart_cart WHERE customer_id = ? AND owner_type = 'customer' AND expires_at > now() + interval '179 days'", [TestTokenFactory::CUSTOMER_ID]);
        self::assertSame($cartId, $added['id']);

        $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $cap, 'quantity' => 1], \JSON_THROW_ON_ERROR), $token);
        $changed = $this->request('PUT', '/api/v1/me/cart/items/'.$shoes, '{"quantity": 5}', $token);
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame(['gross' => 5 * 19_999 + 4_990, 'currency' => 'PLN', 'itemCount' => 6], $changed['totals']);

        $removed = $this->request('DELETE', '/api/v1/me/cart/items/'.$cap, null, $token);
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame($removed, $this->request('GET', '/api/v1/me/cart', token: $token));
        self::assertSame(['itemCount' => 5], $this->request('GET', '/api/v1/me/cart/summary', token: $token));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]));
    }

    public function testEachCustomerHasOwnCart(): void
    {
        $product = $this->product();
        $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $product, 'quantity' => 1], \JSON_THROW_ON_ERROR), TestTokenFactory::customer());

        $other = $this->request('GET', '/api/v1/me/cart', token: TestTokenFactory::customer(['sub' => 'another-customer', 'email' => 'anna@example.com']));

        self::assertNull($other['id']);
        self::assertSame([], $other['lines']);
    }

    public function testAnExpiredCartIsReadAsEmptyAndItsFirstChangeClearsItsLines(): void
    {
        $old = $this->product();
        $new = $this->product();
        $id = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, expiresAt: '-1 second', lines: [[$old, 3]]);
        $token = TestTokenFactory::customer();

        $read = $this->request('GET', '/api/v1/me/cart', token: $token);
        self::assertSame($id, $read['id']);
        self::assertSame([], $read['lines']);
        self::assertSame(['itemCount' => 0], $this->request('GET', '/api/v1/me/cart/summary', token: $token));
        self::assertSame([[$old, 3]], CartFixture::lines($this->connection, $id), 'a read changes nothing');

        $changed = $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $new, 'quantity' => 1], \JSON_THROW_ON_ERROR), $token);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($id, $changed['id']);
        self::assertSame([[$new, 1]], CartFixture::lines($this->connection, $id));
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND expires_at > now() + interval '179 days'", [$id]));
    }

    public function testTheErrorsOfTheGuestCartApplyToTheCustomerCart(): void
    {
        $token = TestTokenFactory::customer();
        $three = $this->product(1_000, 3);
        $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $three, 'quantity' => 1], \JSON_THROW_ON_ERROR), $token);

        $this->assertProblem(404, 'Produkt nie jest już dostępny.', $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => (string) Uuid::v7(), 'quantity' => 1], \JSON_THROW_ON_ERROR), $token));
        $this->assertProblem(404, 'Tej pozycji nie ma w koszyku.', $this->request('PUT', '/api/v1/me/cart/items/'.Uuid::v7(), '{"quantity": 1}', $token));
        $this->assertProblem(409, 'Nie mamy tylu sztuk tego produktu.', $this->request('PUT', '/api/v1/me/cart/items/'.$three, '{"quantity": 4}', $token));
        $this->assertProblem(409, 'Ten produkt jest chwilowo niedostępny w sprzedaży.', $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $this->product(null), 'quantity' => 1], \JSON_THROW_ON_ERROR), $token));
        $this->assertViolation('quantity', $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $three, 'quantity' => 100], \JSON_THROW_ON_ERROR), $token));
        $this->assertViolation('quantity', $this->request('PUT', '/api/v1/me/cart/items/'.$three, '{"quantity": "2"}', $token));
        $this->assertProblem(400, null, $this->request('POST', '/api/v1/me/cart/items', '{', $token));
        $this->assertProblem(415, null, $this->request('PUT', '/api/v1/me/cart/items/'.$three, 'quantity=2', $token, 'application/x-www-form-urlencoded'));
        $this->assertProblem(404, null, $this->request('PUT', '/api/v1/me/cart/items/not-a-uuid', '{"quantity": 1}', $token));
        $this->assertProblem(404, null, $this->request('DELETE', '/api/v1/me/cart/items/not-a-uuid', null, $token));
    }

    public function testAnIncreaseOfALineWhoseProductCatalogForgotIsNotFoundAndADecreaseWorks(): void
    {
        $forgotten = (string) Uuid::v7();
        $id = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, lines: [[$forgotten, 3]]);
        $token = TestTokenFactory::customer();

        $this->assertProblem(404, 'Produkt nie jest już dostępny.', $this->request('PUT', '/api/v1/me/cart/items/'.$forgotten, '{"quantity": 4}', $token));
        self::assertSame([[$forgotten, 3]], CartFixture::lines($this->connection, $id));

        $decreased = $this->request('PUT', '/api/v1/me/cart/items/'.$forgotten, '{"quantity": 2}', $token);
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame($id, $decreased['id']);
        self::assertSame([[$forgotten, 2]], CartFixture::lines($this->connection, $id));
    }

    public function testBadJsonIs400AndAnotherContentTypeIs415OnBothWriteEndpoints(): void
    {
        $token = TestTokenFactory::customer();
        $product = $this->product();
        $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $product, 'quantity' => 1], \JSON_THROW_ON_ERROR), $token);

        $this->assertProblem(400, null, $this->request('POST', '/api/v1/me/cart/items', '{"productId": ', $token));
        $this->assertProblem(400, null, $this->request('PUT', '/api/v1/me/cart/items/'.$product, '{"quantity": ', $token));
        $this->assertProblem(415, null, $this->request('POST', '/api/v1/me/cart/items', 'productId=1&quantity=1', $token, 'application/x-www-form-urlencoded'));
        $this->assertProblem(415, null, $this->request('PUT', '/api/v1/me/cart/items/'.$product, 'quantity=1', $token, 'text/plain'));
    }

    public function testTheLimitOf50ProductsAppliesToTheCustomerCart(): void
    {
        $id = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID);
        for ($i = 1; $i <= 50; ++$i) {
            CartFixture::line($this->connection, $id, \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1);
        }

        $this->assertProblem(409, 'Koszyk może zawierać najwyżej 50 różnych produktów.', $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $this->product(), 'quantity' => 1], \JSON_THROW_ON_ERROR), TestTokenFactory::customer()));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function operations(): iterable
    {
        $product = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
        yield 'get' => ['GET', '/api/v1/me/cart', null];
        yield 'summary' => ['GET', '/api/v1/me/cart/summary', null];
        yield 'add' => ['POST', '/api/v1/me/cart/items', '{"productId": "'.$product.'", "quantity": 1}'];
        yield 'change' => ['PUT', '/api/v1/me/cart/items/'.$product, '{"quantity": 1}'];
        yield 'remove' => ['DELETE', '/api/v1/me/cart/items/'.$product, null];
    }

    #[DataProvider('operations')]
    public function testWithoutATokenOrWithAStaffTokenItIs401(string $method, string $path, ?string $body): void
    {
        $carts = $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart');
        foreach ([null, TestTokenFactory::staff(['admin']), 'abc'] as $token) {
            $this->assertProblem(401, 'Authentication is required.', $this->request($method, $path, $body, $token));
            self::assertSame('Bearer', $this->response()->headers->get('WWW-Authenticate'));
        }
        self::assertSame($carts, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart'));
    }
}
