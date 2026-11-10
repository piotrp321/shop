<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\Payment\PaymentEndpointTestTrait;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The rate limits of the order placement, the payment start and the webhooks (spec: docs/features/hardening/spec.md,
 * "Rate limiting", D4–D8) with the small limits of `when@test` (config/packages/rate_limiter.yaml) on an array pool,
 * which lives as long as the kernel of one test. The windows are the production ones. On shop_test, rolled back after
 * each test.
 */
final class RateLimitEndpointTest extends WebTestCase
{
    use Factories;
    use PaymentEndpointTestTrait;

    /** The limits of `when@test`. */
    private const int ORDER_LIMIT = 10;
    private const int PAYMENT_LIMIT = 10;
    private const int WEBHOOK_LIMIT = 10;

    private const string ADDRESS = '198.51.100.1';
    private const string OTHER_ADDRESS = '198.51.100.2';
    private const string TOO_MANY = 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.';
    private const string OTHER_CUSTOMER = '0b0f6c0e-2f43-4c8a-9d4e-7a1b2c3d4e5f';

    protected function tearDown(): void
    {
        unset($_SERVER['CLIENT_IP_HEADER'], $_ENV['CLIENT_IP_HEADER']);
        putenv('CLIENT_IP_HEADER');
        parent::tearDown();
    }

    public function testAGuestOverTheLimitOfOrdersIsRefusedBeforeAnythingIsOrderedAndAnotherAddressPasses(): void
    {
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->place('/api/v1/orders', []);
            self::assertResponseStatusCodeSame(422, 'a request refused by the validation counts too');
        }
        $shoes = $this->variant(self::GROSS, 10);
        $cart = CartFixture::cart($this->connection, lines: [[$shoes, 1]]);

        $problem = $this->place('/api/v1/orders', $this->guestBody($cart), key: 'order-over-the-limit');

        $this->assertTooManyRequests($problem, 15 * 60);
        self::assertSame(0, $this->orders(), 'nothing is ordered');
        self::assertSame([[$shoes, 1]], CartFixture::lines($this->connection, $cart), 'the cart is as it was');

        $this->from(self::OTHER_ADDRESS);
        $this->place('/api/v1/orders', $this->guestBody($cart), key: 'order-over-the-limit');
        self::assertResponseStatusCodeSame(201, 'the same key orders from another address: the refused request used nothing');
    }

    public function testARepeatedIdempotencyKeyUsesTheLimitToo(): void
    {
        $this->from(self::ADDRESS);
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(self::GROSS, 10), 1]]);
        $this->place('/api/v1/orders', $this->guestBody($cart));
        self::assertResponseStatusCodeSame(201);
        for ($attempt = 2; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->place('/api/v1/orders', $this->guestBody($cart));
            self::assertResponseStatusCodeSame(200);
        }

        $this->assertTooManyRequests($this->place('/api/v1/orders', $this->guestBody($cart)), 15 * 60);
    }

    public function testTheLimitOfACustomerCountsByTheAccountWhateverTheAddress(): void
    {
        $customer = TestTokenFactory::customer();
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->from('203.0.113.'.$attempt);
            $this->place('/api/v1/me/orders', [], token: $customer);
            self::assertResponseStatusCodeSame(422);
        }

        $this->from('203.0.113.100');
        $this->assertTooManyRequests($this->place('/api/v1/me/orders', [], token: $customer), 15 * 60);

        $this->place('/api/v1/me/orders', [], token: TestTokenFactory::customer(['sub' => self::OTHER_CUSTOMER]));
        self::assertResponseStatusCodeSame(422, 'another customer from the same address is not limited');
        $this->place('/api/v1/orders', []);
        self::assertResponseStatusCodeSame(422, 'a guest from the same address is not limited');
    }

    public function testTheLimitsOfGuestsAndCustomersHaveTheirOwnKeys(): void
    {
        // A customer whose `sub` is the address of the guest still has a key of their own.
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->place('/api/v1/orders', []);
            self::assertResponseStatusCodeSame(422);
        }
        $this->assertTooManyRequests($this->place('/api/v1/orders', []), 15 * 60);

        $this->place('/api/v1/me/orders', [], token: TestTokenFactory::customer(['sub' => self::ADDRESS]));
        self::assertResponseStatusCodeSame(422);
        $this->place('/api/v1/me/orders', [], token: TestTokenFactory::customer());
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheOrdersAndThePaymentsHaveSeparateLimits(): void
    {
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->place('/api/v1/orders', []);
        }
        $this->assertTooManyRequests($this->place('/api/v1/orders', []), 15 * 60);

        $this->startPayment('/api/v1/orders/'.self::UNKNOWN_ORDER.'/payments', accessToken: 'token');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestOverTheLimitOfPaymentStartsIsRefusedBeforeTheOrderIsLookedUp(): void
    {
        $order = $this->guestOrder();
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::PAYMENT_LIMIT; ++$attempt) {
            $this->startPayment('/api/v1/orders/'.self::UNKNOWN_ORDER.'/payments', accessToken: 'token');
            self::assertResponseStatusCodeSame(404);
        }

        $problem = $this->startPayment('/api/v1/orders/'.$order['orderId'].'/payments', accessToken: $order['accessToken']);

        $this->assertTooManyRequests($problem, 15 * 60);
        self::assertSame(0, $this->payments(), 'no payment is started');

        $this->from(self::OTHER_ADDRESS);
        $this->assertStarted($this->startPayment('/api/v1/orders/'.$order['orderId'].'/payments', accessToken: $order['accessToken']), 'demo-transfer');
    }

    public function testTheLimitOfPaymentStartsOfACustomerCountsByTheAccount(): void
    {
        $customer = TestTokenFactory::customer();
        for ($attempt = 1; $attempt <= self::PAYMENT_LIMIT; ++$attempt) {
            $this->from('203.0.113.'.$attempt);
            $this->startPayment('/api/v1/me/orders/'.self::UNKNOWN_ORDER.'/payments', bearer: $customer);
            self::assertResponseStatusCodeSame(404);
        }

        $this->from('203.0.113.100');
        $this->assertTooManyRequests($this->startPayment('/api/v1/me/orders/'.self::UNKNOWN_ORDER.'/payments', bearer: $customer), 15 * 60);

        $this->startPayment('/api/v1/me/orders/'.self::UNKNOWN_ORDER.'/payments', bearer: TestTokenFactory::customer(['sub' => self::OTHER_CUSTOMER]));
        self::assertResponseStatusCodeSame(404, 'another customer is not limited');
    }

    public function testAWebhookOverTheLimitIsRefusedBeforeItsSignatureIsChecked(): void
    {
        [$order, $payment, $transaction] = $this->startedTransfer();
        $this->from(self::ADDRESS);
        $body = $this->transferBody($transaction);
        for ($attempt = 1; $attempt <= self::WEBHOOK_LIMIT; ++$attempt) {
            $this->postWebhook('demo-transfer', $body, ['X-Signature' => 'wrong']);
            self::assertResponseStatusCodeSame(400);
        }

        $this->assertTooManyRequests($this->postWebhook('demo-transfer', $body, ['X-Signature' => 'wrong']), 60);
        $this->assertTooManyRequests($this->postWebhook('demo-transfer', $body, $this->transferSignature($body)), 60);
        self::assertSame('pending', $this->paymentStatus($payment), 'the webhook was not handled');
        self::assertSame(['new', null], $this->orderPayment($order['orderId']));

        $this->postWebhook('demo-card', '{}', []);
        self::assertResponseStatusCodeSame(400, 'another provider from the same address has its own limit');
        $this->from(self::OTHER_ADDRESS);
        $this->postWebhook('demo-transfer', $body, $this->transferSignature($body));
        self::assertResponseStatusCodeSame(204, 'another address of the provider has its own limit');
        self::assertSame('succeeded', $this->paymentStatus($payment));
    }

    public function testAForgedClientAddressHeaderIsIgnoredWithoutTheVariable(): void
    {
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.'.$attempt);
            $this->place('/api/v1/orders', []);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.100');
        $this->assertTooManyRequests($this->place('/api/v1/orders', []), 15 * 60);
    }

    public function testWithTheVariableTheAddressOfTheHeaderCounts(): void
    {
        $this->restartWithClientIpHeader('CF-Connecting-IP');
        $this->from(self::ADDRESS);
        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.7');
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->place('/api/v1/orders', []);
            self::assertResponseStatusCodeSame(422);
        }

        $this->from(self::OTHER_ADDRESS);
        $this->assertTooManyRequests($this->place('/api/v1/orders', []), 15 * 60);

        $this->from(self::ADDRESS);
        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.8');
        $this->place('/api/v1/orders', []);
        self::assertResponseStatusCodeSame(422, 'another client behind the same connection is not limited');

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '203.0.113.7, 203.0.113.9');
        $this->place('/api/v1/orders', []);
        self::assertResponseStatusCodeSame(422, 'two addresses: the address of the connection counts, which is not limited');
    }

    public function testAnIpv6ClientCountsByItsSlash64(): void
    {
        $this->restartWithClientIpHeader('CF-Connecting-IP');
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12::'.dechex($attempt));
            $this->place('/api/v1/orders', []);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:12:ffff::1');
        $this->assertTooManyRequests($this->place('/api/v1/orders', []), 15 * 60);

        $this->client->setServerParameter('HTTP_CF_CONNECTING_IP', '2001:db8:abcd:13::1');
        $this->place('/api/v1/orders', []);
        self::assertResponseStatusCodeSame(422, 'another /64 is not limited');
    }

    public function testEndpointsWithoutALimitAreNotLimited(): void
    {
        $this->from(self::ADDRESS);
        for ($attempt = 1; $attempt <= 2 * self::ORDER_LIMIT; ++$attempt) {
            $this->client->request('GET', '/api/v1/payment-providers');
            self::assertResponseStatusCodeSame(200);
            $this->readStatus('/api/v1/orders/'.self::UNKNOWN_ORDER.'/payment', accessToken: 'token');
            self::assertResponseStatusCodeSame(404);
        }
    }

    private const string UNKNOWN_ORDER = '0199a0b0-0000-7000-8000-000000000999';

    private function from(string $address): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', $address);
    }

    /**
     * A new kernel that reads CLIENT_IP_HEADER (the variable is read when the kernel starts).
     */
    private function restartWithClientIpHeader(string $header): void
    {
        $_SERVER['CLIENT_IP_HEADER'] = $_ENV['CLIENT_IP_HEADER'] = $header;
        putenv('CLIENT_IP_HEADER='.$header);
        self::ensureKernelShutdown();
        $this->setUp();
    }

    /**
     * @return array<string, mixed>
     */
    private function guestBody(string $cart): array
    {
        return ['cartId' => $cart, 'email' => 'anna.nowak@example.com', ...self::details(self::GROSS)];
    }

    /**
     * A guest order with a pending payment at `demo-transfer`, made from an address of its own.
     *
     * @return array{array{orderId: string, number: string, accessToken: string}, string, string} the order, the id of
     *                                                                                            the payment and the
     *                                                                                            transaction
     */
    private function startedTransfer(): array
    {
        $this->from('192.0.2.1');
        $order = $this->guestOrder();
        $payment = $this->startPayment('/api/v1/orders/'.$order['orderId'].'/payments', accessToken: $order['accessToken']);
        $transaction = $this->assertStarted($payment, 'demo-transfer');
        \assert(\is_string($payment['paymentId']));

        return [$order, $payment['paymentId'], $transaction];
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertTooManyRequests(array $problem, int $window): void
    {
        $this->assertProblem(429, self::TOO_MANY, $problem);
        self::assertSame(['type' => 'about:blank', 'title' => 'Too Many Requests', 'status' => 429, 'detail' => self::TOO_MANY], $problem);
        $retryAfter = $this->response()->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/D', $retryAfter, 'whole seconds');
        self::assertLessThanOrEqual($window + 1, (int) $retryAfter, 'at most the window, rounded up to whole seconds');
    }

    public function transferBody(string $transaction): string
    {
        return json_encode([
            'eventId' => 'evt_'.str_replace('-', '', $transaction),
            'transactionId' => $transaction,
            'merchantRef' => 'R-100001',
            'status' => 'PAID',
            'amount' => self::GROSS,
            'currency' => 'PLN',
            'paidAt' => new \DateTimeImmutable()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    private function transferSignature(string $body): array
    {
        $secret = $_SERVER['PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET'] ?? $_ENV['PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET'] ?? getenv('PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET');
        self::assertIsString($secret);

        return ['X-Signature' => hash_hmac('sha256', $body, $secret)];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    private function postWebhook(string $provider, string $body, array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->client->request('POST', '/api/v1/payments/webhooks/'.$provider, server: $server, content: $body);

        return $this->decoded();
    }

    /**
     * @return array{string, string|null} the status of the order and the payment that paid it
     */
    private function orderPayment(string $orderId): array
    {
        $row = $this->connection->fetchNumeric('SELECT status, payment_id FROM ordering_order WHERE id = ?', [$orderId]);
        \assert(\is_array($row) && \is_string($row[0]) && (null === $row[1] || \is_string($row[1])));

        return [$row[0], $row[1]];
    }
}
