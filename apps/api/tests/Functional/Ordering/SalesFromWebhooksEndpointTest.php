<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Functional\Payment\PaymentEndpointTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The hourly sales as the webhooks of the payments write them (POST /api/v1/payments/webhooks/demo-transfer, signed as
 * the provider signs it): a payment that pays the order adds it to the hour of its payment once, and a repeated
 * delivery, a refusal and a webhook rolled back after MarkOrderPaid add nothing. On shop_test, rolled back after each
 * test; the sales table is emptied first in the same transaction.
 */
final class SalesFromWebhooksEndpointTest extends WebTestCase
{
    use Factories;
    use PaymentEndpointTestTrait {
        setUp as private setUpClient;
    }

    protected function setUp(): void
    {
        $this->setUpClient();
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
    }

    public function testAPaidTransferAddsTheOrderToTheHourOfItsPaymentOnce(): void
    {
        [$orderId, $transaction] = $this->payment();

        $this->postWebhook($this->transferBody($transaction));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[$this->hourOfPayment($orderId), 1, self::GROSS]], $this->sales());
    }

    public function testARepeatedDeliveryAddsNothing(): void
    {
        [$orderId, $transaction] = $this->payment();
        $this->postWebhook($this->transferBody($transaction));

        $this->postWebhook($this->transferBody($transaction));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([[$this->hourOfPayment($orderId), 1, self::GROSS]], $this->sales());
    }

    public function testARejectedTransferAddsNothing(): void
    {
        [, $transaction] = $this->payment();

        $this->postWebhook($this->transferBody($transaction, 'REJECTED'));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->sales());
    }

    public function testAWebhookRolledBackAfterThePaymentOfTheOrderAddsNothing(): void
    {
        [$orderId, $transaction] = $this->payment();
        // Payment saves its payment after MarkOrderPaid has paid the order and added it to the sales: the failure
        // there rolls the whole webhook back.
        $this->connection->executeStatement("CREATE FUNCTION pg_temp.fail_payment_update() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of the payment'; END \$\$");
        $this->connection->executeStatement('CREATE TRIGGER fail_payment_update BEFORE UPDATE ON payment_payment FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_payment_update()');

        $this->postWebhook($this->transferBody($transaction));

        self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode());
        self::assertSame('new', $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame([], $this->sales());
    }

    /**
     * A guest order with a payment started at the transfer provider.
     *
     * @return array{string, string} the order id and the transaction at the provider
     */
    private function payment(): array
    {
        $order = $this->guestOrder();
        $payment = $this->startPayment('/api/v1/orders/'.$order['orderId'].'/payments', ['provider' => 'demo-transfer'], accessToken: $order['accessToken']);
        self::assertResponseStatusCodeSame(201);

        return [$order['orderId'], $this->assertStarted($payment, 'demo-transfer')];
    }

    private function transferBody(string $transaction, string $status = 'PAID'): string
    {
        return json_encode([
            'eventId' => 'evt_'.str_replace('-', '', $transaction),
            'transactionId' => $transaction,
            'merchantRef' => 'R-100001',
            'status' => $status,
            'amount' => self::GROSS,
            'currency' => 'PLN',
            'paidAt' => new \DateTimeImmutable()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function postWebhook(string $body): void
    {
        $secret = $_SERVER['PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET'] ?? $_ENV['PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET'] ?? getenv('PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET');
        \assert(\is_string($secret) && '' !== $secret);
        $this->client->request('POST', '/api/v1/payments/webhooks/demo-transfer', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, $secret),
        ], content: $body);
    }

    private function hourOfPayment(string $orderId): string
    {
        $hour = $this->connection->fetchOne("SELECT to_char(date_trunc('hour', paid_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD HH24:00') FROM ordering_order WHERE id = ?", [$orderId]);
        \assert(\is_string($hour));

        return $hour;
    }

    /**
     * @return list<array{string, int, int}> the hour (UTC), the number and the gross total of every hour with sales
     */
    private function sales(): array
    {
        /** @var list<array{string, int, int}> $rows */
        $rows = $this->connection->fetchAllNumeric("SELECT to_char(hour_start AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:00'), CAST(SUM(order_count) AS int), CAST(SUM(gross) AS int) FROM ordering_sales_hour GROUP BY hour_start ORDER BY hour_start");

        return $rows;
    }
}
