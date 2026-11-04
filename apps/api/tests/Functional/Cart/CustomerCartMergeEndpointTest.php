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
 * POST /api/v1/me/cart/merge: the guest cart into the cart of the customer of the token.
 */
final class CustomerCartMergeEndpointTest extends WebTestCase
{
    use CartEndpointTestTrait;
    use CartRequestViolationCases;
    use Factories;

    private const string PATH = '/api/v1/me/cart/merge';

    public function testTheGuestLinesMoveIntoTheCustomerCartAndTheGuestCartIsGoneForGood(): void
    {
        $token = TestTokenFactory::customer();
        $shoes = $this->product(19_999, 10);
        $cap = $this->product(4_990, 10);
        $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $shoes, 'quantity' => 1], \JSON_THROW_ON_ERROR), $token);
        $guest = $this->request('POST', '/api/v1/guest-carts');
        self::assertIsString($guest['id']);
        $guestPath = '/api/v1/guest-carts/'.$guest['id'];
        $this->request('POST', $guestPath.'/items', json_encode(['productId' => $shoes, 'quantity' => 2], \JSON_THROW_ON_ERROR));
        $this->request('POST', $guestPath.'/items', json_encode(['productId' => $cap, 'quantity' => 1], \JSON_THROW_ON_ERROR));

        $merged = $this->request('POST', self::PATH, json_encode(['guestCartId' => $guest['id']], \JSON_THROW_ON_ERROR), $token);

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('MergeResult', $merged);
        self::assertSame('merged', $merged['result']);
        self::assertFalse($merged['truncated']);
        self::assertIsArray($merged['cart']);
        self::assertSame($this->connection->fetchOne('SELECT id FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]), $merged['cart']['id']);
        self::assertSame(['gross' => 3 * 19_999 + 4_990, 'currency' => 'PLN', 'itemCount' => 4], $merged['cart']['totals']);
        self::assertSame($merged['cart'], $this->request('GET', '/api/v1/me/cart', token: $token));
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND status = 'merged' AND merged_into = ? AND merged_at IS NOT NULL", [$guest['id'], $merged['cart']['id']]));
        self::assertSame([], CartFixture::lines($this->connection, $guest['id']));

        $updatedAt = $this->connection->fetchOne('SELECT updated_at FROM cart_cart WHERE id = ?', [$guest['id']]);
        foreach ([
            ['GET', $guestPath, null],
            ['GET', $guestPath.'/summary', null],
            ['POST', $guestPath.'/items', json_encode(['productId' => $cap, 'quantity' => 1], \JSON_THROW_ON_ERROR)],
            ['PUT', $guestPath.'/items/'.$cap, '{"quantity": 1}'],
            ['DELETE', $guestPath.'/items/'.$cap, null],
        ] as [$method, $path, $body]) {
            $this->assertProblem(404, 'Koszyk nie jest już dostępny.', $this->request($method, $path, $body));
        }
        self::assertSame([], CartFixture::lines($this->connection, $guest['id']));
        self::assertSame($updatedAt, $this->connection->fetchOne('SELECT updated_at FROM cart_cart WHERE id = ?', [$guest['id']]));

        $again = $this->request('POST', self::PATH, json_encode(['guestCartId' => $guest['id']], \JSON_THROW_ON_ERROR), $token);
        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame(['cart' => $merged['cart'], 'result' => 'already_merged', 'truncated' => false], $again);
    }

    public function testACutSumIsTruncated(): void
    {
        $product = $this->product(1_000, 200);
        $customer = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, expiresAt: '+10 days', lines: [[$product, 98]]);
        $guest = CartFixture::cart($this->connection, lines: [[$product, 5]]);

        $merged = $this->request('POST', self::PATH, json_encode(['guestCartId' => $guest], \JSON_THROW_ON_ERROR), TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertSame('merged', $merged['result']);
        self::assertTrue($merged['truncated']);
        self::assertSame([[$product, 99]], CartFixture::lines($this->connection, $customer));
    }

    public function testASkippedLineIsTruncated(): void
    {
        $customer = CartFixture::cart($this->connection, customerId: TestTokenFactory::CUSTOMER_ID, expiresAt: '+10 days');
        for ($i = 1; $i <= 50; ++$i) {
            CartFixture::line($this->connection, $customer, \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), 1);
        }
        $guest = CartFixture::cart($this->connection, lines: [[(string) Uuid::v7(), 1]]);

        $merged = $this->request('POST', self::PATH, json_encode(['guestCartId' => $guest], \JSON_THROW_ON_ERROR), TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('merged', $merged['result']);
        self::assertTrue($merged['truncated']);
        self::assertIsArray($merged['cart']);
        self::assertIsArray($merged['cart']['lines']);
        self::assertCount(50, $merged['cart']['lines']);
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND status = 'merged'", [$guest]));
    }

    public function testAnEmptyGuestCartIsMergedAndTheFirstMergeCreatesTheCustomerCart(): void
    {
        $guest = CartFixture::cart($this->connection);

        $merged = $this->request('POST', self::PATH, json_encode(['guestCartId' => $guest], \JSON_THROW_ON_ERROR), TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        self::assertMatchesSchema('MergeResult', $merged);
        self::assertSame('merged', $merged['result']);
        self::assertFalse($merged['truncated']);
        self::assertIsArray($merged['cart']);
        self::assertSame($this->connection->fetchOne('SELECT id FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]), $merged['cart']['id']);
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND status = 'merged'", [$guest]));
    }

    public function testNothingToMergeGivesTheCartOfTheCustomerWithoutChange(): void
    {
        $token = TestTokenFactory::customer();
        $product = $this->product();
        $empty = ['cart' => ['id' => null, 'lines' => [], 'totals' => ['gross' => 0, 'currency' => 'PLN', 'itemCount' => 0], 'hasExcludedLines' => false], 'result' => 'nothing_to_merge', 'truncated' => false];
        $expired = CartFixture::cart($this->connection, expiresAt: '-1 second', lines: [[$product, 1]]);
        $other = CartFixture::cart($this->connection, customerId: 'another-customer', lines: [[$product, 1]]);

        foreach ([(string) Uuid::v4(), $expired, $other] as $guestCartId) {
            $result = $this->request('POST', self::PATH, json_encode(['guestCartId' => $guestCartId], \JSON_THROW_ON_ERROR), $token);
            self::assertResponseStatusCodeSame(200);
            $this->assertNoStore();
            self::assertSame($empty, $result, 'a customer without a cart gets the empty one');
        }
        self::assertMatchesSchema('MergeResult', $empty);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]), 'nothing is created');
        self::assertSame([[$product, 1]], CartFixture::lines($this->connection, $expired));
        self::assertSame([[$product, 1]], CartFixture::lines($this->connection, $other));

        $own = $this->request('POST', '/api/v1/me/cart/items', json_encode(['productId' => $product, 'quantity' => 2], \JSON_THROW_ON_ERROR), $token);
        $result = $this->request('POST', self::PATH, json_encode(['guestCartId' => $own['id']], \JSON_THROW_ON_ERROR), $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cart' => $own, 'result' => 'nothing_to_merge', 'truncated' => false], $result);
    }

    public function testAnInvalidBodyIsRefusedWithoutChange(): void
    {
        $token = TestTokenFactory::customer();
        $guest = CartFixture::cart($this->connection, lines: [[(string) Uuid::v7(), 1]]);

        $this->assertProblem(400, null, $this->request('POST', self::PATH, '{"guestCartId": ', $token));
        $this->assertProblem(415, null, $this->request('POST', self::PATH, 'guestCartId='.$guest, $token, 'application/x-www-form-urlencoded'));
        foreach (['{}', '{"guestCartId": null}', '{"guestCartId": "not-a-uuid"}', '{"guestCartId": 12}', '{"guestCartId": ["'.$guest.'"]}', ''] as $body) {
            $this->assertViolation('guestCartId', $this->request('POST', self::PATH, $body, $token));
        }

        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND status = 'open'", [$guest]));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [TestTokenFactory::CUSTOMER_ID]));
    }

    /**
     * @param list<array{string, string}> $violations
     */
    #[DataProvider('invalidMergeBodies')]
    public function testAnInvalidMergeBodyGivesPolishViolations(string $body, array $violations): void
    {
        $guest = CartFixture::cart($this->connection, lines: [[(string) Uuid::v7(), 1]]);

        $this->assertViolations($violations, $this->request('POST', self::PATH, $body, TestTokenFactory::customer()));
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND status = 'open'", [$guest]));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'no token' => [null];
        yield 'staff token' => ['staff'];
        yield 'invalid token' => ['abc'];
    }

    #[DataProvider('refusedTokens')]
    public function testWithoutATokenOrWithAStaffTokenItIs401(?string $token): void
    {
        $guest = CartFixture::cart($this->connection, lines: [[(string) Uuid::v7(), 1]]);

        $problem = $this->request('POST', self::PATH, json_encode(['guestCartId' => $guest], \JSON_THROW_ON_ERROR), 'staff' === $token ? TestTokenFactory::staff(['admin']) : $token);

        $this->assertProblem(401, 'Authentication is required.', $problem);
        self::assertSame('Bearer', $this->response()->headers->get('WWW-Authenticate'));
        self::assertSame(1, $this->connection->fetchOne("SELECT COUNT(*) FROM cart_cart WHERE id = ? AND status = 'open'", [$guest]));
    }
}
