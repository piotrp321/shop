<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Tests\Functional\Ordering\OrderEndpointTestTrait;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the tests of the payment endpoints share, on top of the order helpers: orders placed through the order
 * endpoints, starts and status reads of payments, the rows of the simulator and of Payment, and the checks of
 * conflicts against PaymentConflictProblem. The calls to the simulator go to another kernel in the process
 * (KernelForwardingHttpClient) on the same transaction; on shop_test, rolled back after each test.
 */
trait PaymentEndpointTestTrait
{
    use OrderEndpointTestTrait;

    private const int GROSS = 12_300;
    private const string PAY_KEY = 'pay-0192f0c4-7c1a-7c4e-9a6e-000000000001';

    /**
     * @return array{orderId: string, number: string, accessToken: string}
     */
    private function guestOrder(): array
    {
        $cart = CartFixture::cart($this->connection, lines: [[$this->variant(self::GROSS, 10), 1]]);
        $order = $this->place('/api/v1/orders', ['cartId' => $cart, 'email' => 'anna.nowak@example.com', ...self::details(self::GROSS)], key: 'order-'.bin2hex(random_bytes(6)));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::CONTACT, array_intersect_key($order, self::CONTACT));
        \assert(\is_string($order['orderId']) && \is_string($order['number']) && \is_string($order['accessToken']));

        return ['orderId' => $order['orderId'], 'number' => $order['number'], 'accessToken' => $order['accessToken']];
    }

    /**
     * @return array{orderId: string, number: string}
     */
    private function customerOrder(string $customerId = TestTokenFactory::CUSTOMER_ID): array
    {
        $variant = $this->variant(self::GROSS, 10);
        // The cart of the customer stays (emptied) after an order.
        $cart = $this->connection->fetchOne('SELECT id FROM cart_cart WHERE customer_id = ?', [$customerId]);
        if (\is_string($cart)) {
            CartFixture::line($this->connection, $cart, $variant, 1);
        } else {
            CartFixture::cart($this->connection, customerId: $customerId, lines: [[$variant, 1]]);
        }
        $order = $this->place('/api/v1/me/orders', self::details(self::GROSS), key: 'order-'.bin2hex(random_bytes(6)), token: TestTokenFactory::customer(['sub' => $customerId]));
        self::assertResponseStatusCodeSame(201);
        \assert(\is_string($order['orderId']) && \is_string($order['number']));

        return ['orderId' => $order['orderId'], 'number' => $order['number']];
    }

    /**
     * POST of a start. An array body is sent as JSON, a string as it is; a null key or token sends no header.
     *
     * @param array<mixed>|string $body
     *
     * @return array<mixed>
     */
    private function startPayment(string $path, array|string $body = ['provider' => 'demo-transfer'], ?string $key = self::PAY_KEY, ?string $accessToken = null, ?string $bearer = null, string $contentType = 'application/json'): array
    {
        $server = ['CONTENT_TYPE' => $contentType];
        if (null !== $key) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        if (null !== $accessToken) {
            $server['HTTP_ORDER_ACCESS_TOKEN'] = $accessToken;
        }
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request('POST', $path, server: $server, content: \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);

        return $this->decoded();
    }

    /**
     * @return array<mixed>
     */
    private function readStatus(string $path, ?string $accessToken = null, ?string $bearer = null): array
    {
        $server = [];
        if (null !== $accessToken) {
            $server['HTTP_ORDER_ACCESS_TOKEN'] = $accessToken;
        }
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $this->client->request('GET', $path, server: $server);

        return $this->decoded();
    }

    /**
     * @return array<mixed>
     */
    private function decoded(): array
    {
        $this->entityManager->clear();
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * A started payment: the PaymentView schema, pending, at the page of the simulator for its transaction.
     *
     * @param array<mixed> $payment
     *
     * @return string the id of the transaction of the simulator
     */
    private function assertStarted(array $payment, string $provider): string
    {
        self::assertMatchesSchema('PaymentView', $payment);
        self::assertSame($provider, $payment['provider']);
        self::assertSame('pending', $payment['status']);
        self::assertIsString($payment['paymentId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $payment['paymentId']);
        self::assertIsString($payment['redirectUrl']);
        self::assertMatchesRegularExpression('#/payment-simulator/'.$provider.'/pay/([0-9a-f-]{36})$#', $payment['redirectUrl']);
        $transaction = substr($payment['redirectUrl'], -36);
        self::assertSame($transaction, $this->connection->fetchOne('SELECT provider_payment_id FROM payment_payment WHERE id = ?', [$payment['paymentId']]));

        return $transaction;
    }

    /**
     * @return array<string, mixed> the row of the transaction of the simulator
     */
    private function transaction(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM payment_simulator_transaction WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function paymentStatus(string $paymentId): string
    {
        $status = $this->connection->fetchOne('SELECT status FROM payment_payment WHERE id = ?', [$paymentId]);
        self::assertIsString($status);

        return $status;
    }

    private function payments(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM payment_payment');
        self::assertIsInt($count);

        return $count;
    }

    private function markOrderPaid(string $orderId): void
    {
        $this->connection->executeStatement("UPDATE ordering_order SET status = 'paid', paid_at = now(), payment_id = gen_random_uuid() WHERE id = ?", [$orderId]);
    }

    private function cancelOrder(string $orderId): void
    {
        $this->connection->executeStatement("UPDATE ordering_order SET status = 'cancelled', cancelled_at = now(), cancellation_reason = 'payment_timeout' WHERE id = ?", [$orderId]);
    }

    private function passTheDeadline(string $orderId): void
    {
        $this->connection->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '1 second' WHERE id = ?", [$orderId]);
    }

    /**
     * The simulator answers every call with this response (e.g. a 500 or a network error).
     */
    private function providerAnswers(MockResponse $response): void
    {
        $client = self::getContainer()->get('app.payment_http_client');
        self::assertInstanceOf(MockHttpClient::class, $client);
        $client->setResponseFactory($response);
    }

    /**
     * A 409 with the stable `type` of its cause, in the enum of PaymentConflictProblem, and the Polish `detail`.
     *
     * @param array<mixed> $problem
     */
    private function assertPaymentConflict(string $slug, string $detail, array $problem): void
    {
        $this->assertProblem(409, $detail, $problem);
        self::assertSame('tag:piotrp321.github.io,2026:shop/problems/'.$slug, $problem['type'] ?? null);
        self::assertSame(['type', 'title', 'status', 'detail'], array_keys($problem));
        $node = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach (['components', 'schemas', 'PaymentConflictProblem', 'properties', 'type', 'enum'] as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node, 'openapi.json has PaymentConflictProblem');
            $node = $node[$key];
        }
        self::assertIsArray($node);
        self::assertContains($problem['type'], $node, 'the type is in the enum of PaymentConflictProblem');
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertOrderNotFound(array $problem): void
    {
        $this->assertProblem(404, 'Nie znaleźliśmy zamówienia.', $problem);
    }

    /**
     * A 503 without `detail`, `private, no-store`.
     *
     * @param array<mixed> $problem
     */
    private function assertUnavailable(array $problem): void
    {
        $this->assertProblem(503, null, $problem);
        self::assertArrayNotHasKey('detail', $problem);
    }
}
