<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /api/v1/payments/webhooks/{provider}: the providers deliver signed events without a token. A payment is
 * started through the API at the simulator in the same process, then its webhook is posted as the provider signs it
 * (the secrets of the environment). On shop_test, rolled back after each test.
 */
final class WebhookEndpointTest extends WebTestCase
{
    use Factories;
    use PaymentEndpointTestTrait;

    private const string GENERIC_400 = 'The request is invalid.';

    public function testAPaidTransferPaysTheOrder(): void
    {
        [$order, $payment, $transaction] = $this->payment('demo-transfer');

        $this->postWebhook('demo-transfer', $this->transferBody($transaction), $this->transferSignature($this->transferBody($transaction)));

        $this->assertNoContent();
        self::assertSame('succeeded', $this->paymentStatus($payment));
        self::assertSame(['paid', $payment], $this->orderPayment($order['orderId']));
        self::assertSame(1, $this->webhookEvents());
    }

    public function testACompletedCardCheckoutPaysTheOrder(): void
    {
        [$order, $payment, $transaction] = $this->payment('demo-card');
        $body = $this->cardBody($transaction);

        $this->postWebhook('demo-card', $body, $this->cardSignature($body));

        $this->assertNoContent();
        self::assertSame('succeeded', $this->paymentStatus($payment));
        self::assertSame(['paid', $payment], $this->orderPayment($order['orderId']));
    }

    public function testARejectedTransferFailsThePayment(): void
    {
        [$order, $payment, $transaction] = $this->payment('demo-transfer');
        $body = $this->transferBody($transaction, status: 'REJECTED');

        $this->postWebhook('demo-transfer', $body, $this->transferSignature($body));

        $this->assertNoContent();
        self::assertSame('failed', $this->paymentStatus($payment));
        self::assertSame(['new', null], $this->orderPayment($order['orderId']));
        self::assertSame(1, $this->webhookEvents());
    }

    public function testAnEventOfAnotherTypeIsAcknowledgedWithoutChanges(): void
    {
        [$order, $payment, $transaction] = $this->payment('demo-card');
        $body = json_encode(['id' => 'evt_other', 'type' => 'checkout.expired', 'data' => ['sessionId' => $transaction]], \JSON_THROW_ON_ERROR);

        $this->postWebhook('demo-card', $body, $this->cardSignature($body));

        $this->assertNoContent();
        self::assertSame('pending', $this->paymentStatus($payment));
        self::assertSame(['new', null], $this->orderPayment($order['orderId']));
        self::assertSame(0, $this->webhookEvents());
    }

    /**
     * @return iterable<string, array{string, \Closure(self, string): array{string, array<string, string>}}>
     */
    public static function invalidWebhooks(): iterable
    {
        yield 'transfer: no signature' => ['demo-transfer', static fn (self $test, string $tx): array => [$test->transferBody($tx), []]];
        yield 'transfer: a wrong signature' => ['demo-transfer', static fn (self $test, string $tx): array => [$test->transferBody($tx), ['X-Signature' => hash_hmac('sha256', $test->transferBody($tx), 'another-secret')]]];
        yield 'transfer: a changed body' => ['demo-transfer', static fn (self $test, string $tx): array => [$test->transferBody($tx, amount: 1), $test->transferSignature($test->transferBody($tx))]];
        yield 'transfer: the signature of demo-card' => ['demo-transfer', static fn (self $test, string $tx): array => [$test->transferBody($tx), $test->cardSignature($test->transferBody($tx))]];
        yield 'transfer: a signed body without paidAt' => ['demo-transfer', static fn (self $test, string $tx): array => [$body = $test->transferBody($tx, paidAt: null), $test->transferSignature($body)]];
        yield 'card: no signature' => ['demo-card', static fn (self $test, string $tx): array => [$test->cardBody($tx), []]];
        yield 'card: a wrong signature' => ['demo-card', static fn (self $test, string $tx): array => [$test->cardBody($tx), ['Card-Signature' => 't='.time().',v1='.str_repeat('a', 64)]]];
        yield 'card: signed 301 s ago' => ['demo-card', static fn (self $test, string $tx): array => [$test->cardBody($tx), $test->cardSignature($test->cardBody($tx), time() - 301)]];
        yield 'card: a signed amount with one decimal' => ['demo-card', static fn (self $test, string $tx): array => [$body = $test->cardBody($tx, amount: '123.0'), $test->cardSignature($body)]];
    }

    /**
     * @param \Closure(self, string): array{string, array<string, string>} $webhook
     */
    #[DataProvider('invalidWebhooks')]
    public function testAnInvalidWebhookIs400WithOneTextAndChangesNothing(string $provider, \Closure $webhook): void
    {
        [$order, $payment, $transaction] = $this->payment($provider);
        [$body, $headers] = $webhook($this, $transaction);

        $problem = $this->postWebhook($provider, $body, $headers);

        $this->assertProblem(400, self::GENERIC_400, $problem);
        $this->assertNoStore();
        self::assertSame('pending', $this->paymentStatus($payment));
        self::assertSame(['new', null], $this->orderPayment($order['orderId']));
        self::assertSame(0, $this->webhookEvents());
    }

    public function testAnUnknownProviderIsNotFound(): void
    {
        [$order, $payment, $transaction] = $this->payment('demo-transfer');
        $body = $this->transferBody($transaction);

        $problem = $this->postWebhook('paypal', $body, $this->transferSignature($body));

        $this->assertProblem(404, 'Resource not found.', $problem);
        $this->assertNoStore();
        self::assertSame('pending', $this->paymentStatus($payment));
        self::assertSame(['new', null], $this->orderPayment($order['orderId']));
    }

    public function testAnUnknownPaymentIsNotFound(): void
    {
        $body = $this->transferBody('0f8b6c1e-2d3a-4b5c-8d9e-0a1b2c3d4e5f');

        $problem = $this->postWebhook('demo-transfer', $body, $this->transferSignature($body));

        $this->assertProblem(404, 'Nie znaleźliśmy płatności.', $problem);
        self::assertSame(0, $this->webhookEvents());
    }

    public function testThePaymentOfASessionIsLookedUpAtTheProviderOfTheWebhook(): void
    {
        [, $payment, $transaction] = $this->payment('demo-transfer');
        $body = $this->cardBody($transaction);

        $problem = $this->postWebhook('demo-card', $body, $this->cardSignature($body));

        $this->assertProblem(404, 'Nie znaleźliśmy płatności.', $problem);
        self::assertSame('pending', $this->paymentStatus($payment));
    }

    public function testAnotherAmountIs422AndChangesNothing(): void
    {
        [$order, $payment, $transaction] = $this->payment('demo-transfer');
        $body = $this->transferBody($transaction, amount: self::GROSS + 1);

        $problem = $this->postWebhook('demo-transfer', $body, $this->transferSignature($body));

        $this->assertProblem(422, 'Kwota płatności nie zgadza się z kwotą zamówienia.', $problem);
        $this->assertNoStore();
        self::assertSame('pending', $this->paymentStatus($payment));
        self::assertSame(['new', null], $this->orderPayment($order['orderId']));
        self::assertSame(0, $this->webhookEvents(), 'the event is rolled back with the rest');
    }

    public function testARepeatedDeliveryIs204AndChangesNothing(): void
    {
        [$order, , $transaction] = $this->payment('demo-card');
        $body = $this->cardBody($transaction);
        $this->postWebhook('demo-card', $body, $this->cardSignature($body));
        $before = $this->snapshot($order['orderId']);

        $this->postWebhook('demo-card', $body, $this->cardSignature($body, time() + 1));

        $this->assertNoContent();
        self::assertSame($before, $this->snapshot($order['orderId']));
        self::assertSame(1, $this->webhookEvents());
    }

    public function testAVersionConflictOfTheOrderIs503AndRollsEverythingBack(): void
    {
        [$order, $payment, $transaction] = $this->payment('demo-transfer');
        // The UPDATE of the order touches no row, as when a concurrent transaction raised its version first: Doctrine
        // reports the version conflict (OptimisticLockException) on the real path of MarkOrderPaid.
        $this->connection->executeStatement('CREATE FUNCTION pg_temp.skip_order_update() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END $$');
        $this->connection->executeStatement('CREATE TRIGGER skip_order_update BEFORE UPDATE ON ordering_order FOR EACH ROW EXECUTE FUNCTION pg_temp.skip_order_update()');
        $body = $this->transferBody($transaction);

        $problem = $this->postWebhook('demo-transfer', $body, $this->transferSignature($body));

        $this->assertUnavailable($problem);
        $this->assertNoStore();
        self::assertSame('pending', $this->paymentStatus($payment));
        self::assertSame(['new', null], $this->orderPayment($order['orderId']));
        self::assertSame(0, $this->webhookEvents(), 'the event is rolled back, so the retry is handled anew');
    }

    public function testATokenOfASessionIsNotNeededButAnInvalidOneIsRefused(): void
    {
        [, $payment, $transaction] = $this->payment('demo-transfer');
        $body = $this->transferBody($transaction);

        $this->postWebhook('demo-transfer', $body, [...$this->transferSignature($body), 'Authorization' => 'Bearer not-a-token']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('pending', $this->paymentStatus($payment));
    }

    public function transferBody(string $transaction, int $amount = self::GROSS, string $status = 'PAID', ?string $paidAt = 'now'): string
    {
        return json_encode([
            'eventId' => 'evt_'.str_replace('-', '', $transaction),
            'transactionId' => $transaction,
            'merchantRef' => 'R-100001',
            'status' => $status,
            'amount' => $amount,
            'currency' => 'PLN',
            ...(null === $paidAt ? [] : ['paidAt' => new \DateTimeImmutable($paidAt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    public function cardBody(string $session, string $amount = '123.00'): string
    {
        return json_encode([
            'id' => 'evt_'.str_replace('-', '', $session),
            'type' => 'checkout.completed',
            'data' => ['sessionId' => $session, 'reference' => 'R-100001', 'amount' => $amount, 'currency' => 'PLN', 'completedAt' => time()],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    public function transferSignature(string $body): array
    {
        return ['X-Signature' => hash_hmac('sha256', $body, self::secret('PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET'))];
    }

    /**
     * @return array<string, string>
     */
    public function cardSignature(string $body, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return ['Card-Signature' => \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, self::secret('PAYMENT_DEMO_CARD_WEBHOOK_SECRET')))];
    }

    /**
     * A guest order with a payment started at the provider.
     *
     * @return array{array{orderId: string, number: string, accessToken: string}, string, string} the order, the id of
     *                                                                                            the payment and the
     *                                                                                            session at the provider
     */
    private function payment(string $provider): array
    {
        $order = $this->guestOrder();
        $payment = $this->startPayment(self::startPath($order), ['provider' => $provider], accessToken: $order['accessToken']);
        self::assertResponseStatusCodeSame(201);
        $transaction = $this->assertStarted($payment, $provider);
        \assert(\is_string($payment['paymentId']));

        return [$order, $payment['paymentId'], $transaction];
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

    private function assertNoContent(): void
    {
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        $this->assertNoStore();
    }

    /**
     * @return array{string, ?string} the status of the order and the payment that paid it
     */
    private function orderPayment(string $orderId): array
    {
        $row = $this->connection->fetchNumeric('SELECT status, payment_id FROM ordering_order WHERE id = ?', [$orderId]);
        \assert(\is_array($row) && \is_string($row[0]) && (null === $row[1] || \is_string($row[1])));

        return [$row[0], $row[1]];
    }

    private function webhookEvents(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM payment_webhook_event');
        \assert(\is_int($count));

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $orderId): array
    {
        return [
            'order' => $this->connection->fetchAssociative('SELECT status, payment_id, paid_at, version FROM ordering_order WHERE id = ?', [$orderId]),
            'payments' => $this->connection->fetchAllAssociative('SELECT id, status, completed_at, executed_at, refund_required_reason FROM payment_payment WHERE order_id = ?', [$orderId]),
            'events' => $this->connection->fetchAllAssociative('SELECT provider, event_id, received_at FROM payment_webhook_event'),
        ];
    }

    private static function secret(string $name): string
    {
        $secret = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        \assert(\is_string($secret) && '' !== $secret);

        return $secret;
    }

    /**
     * @param array{orderId: string} $order
     */
    private static function startPath(array $order): string
    {
        return '/api/v1/orders/'.$order['orderId'].'/payments';
    }
}
