<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Ordering\Domain\OrderPaid;
use App\Payment\Application\Command\StartedPayment;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\PaymentAccess;
use App\PaymentSimulator\DecideTransaction;
use App\PaymentSimulator\DeliverSimulatedWebhook;
use App\PaymentSimulator\Provider;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The whole way of a payment on the `test_messaging` kernel (real Doctrine transports in shop_test, rolled back after
 * each test): a placed order, StartPayment at the simulator, the decision of the customer there, the delivery of the
 * webhook through the transport `payment_simulator` (the worker of the test, the HTTP call forwarded to another
 * kernel on the same connection), the webhook endpoint and HandleProviderWebhook with MarkOrderPaid nested.
 */
final class WebhookFlowTest extends MessagingKernelTestCase
{
    private MessageBusInterface $bus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $bus = self::getContainer()->get('command.bus');
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->bus = $bus;
        $this->entityManager = $entityManager;
    }

    /**
     * @return iterable<string, array{Provider}>
     */
    public static function providers(): iterable
    {
        yield 'demo-transfer' => [Provider::DemoTransfer];
        yield 'demo-card' => [Provider::DemoCard];
    }

    #[DataProvider('providers')]
    public function testAPaymentDecidedAtTheSimulatorPaysTheOrderThroughItsWebhook(Provider $provider): void
    {
        [$orderId, $paymentId, $transaction] = $this->paymentDecided($provider);

        $this->consume('payment_simulator');

        self::assertSame('paid', $this->orderStatus($orderId));
        self::assertSame($paymentId, $this->connection()->fetchOne('SELECT payment_id FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame(['succeeded', null], $this->payment($paymentId));
        self::assertSame(
            $this->connection()->fetchOne('SELECT decided_at FROM payment_simulator_transaction WHERE id = ?', [$transaction]),
            $this->connection()->fetchOne('SELECT executed_at FROM payment_payment WHERE id = ?', [$paymentId]),
            'executed when the customer paid at the provider',
        );
        self::assertSame(1, $this->orderPaidEvents($orderId), 'OrderPaid in the outbox');
        self::assertSame(1, $this->webhookEvents($paymentId));
        self::assertSame(0, $this->rows('payment_simulator'), 'the delivery ended');
        self::assertSame(0, $this->rows('failed'));
    }

    public function testAFailureOfMarkOrderPaidRollsTheWebhookBackAndTheRetriedDeliveryPaysTheOrder(): void
    {
        [$orderId, $paymentId] = $this->paymentDecided(Provider::DemoTransfer);
        $this->connection()->executeStatement("CREATE FUNCTION pg_temp.fail_order_update() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of the order'; END \$\$");
        $this->connection()->executeStatement('CREATE TRIGGER fail_order_update BEFORE UPDATE ON ordering_order FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_order_update()');

        $this->consume('payment_simulator');

        self::assertSame('new', $this->orderStatus($orderId));
        self::assertSame(['pending', null], $this->payment($paymentId), 'the 5xx rolled the payment back');
        self::assertSame(0, $this->webhookEvents($paymentId), 'and the deduplication, so the retry is handled anew');
        self::assertSame(1, $this->rows('payment_simulator'), 'the simulator retries');

        $this->connection()->executeStatement('DROP TRIGGER fail_order_update ON ordering_order');
        $this->elapseRetryDelay();
        $this->consume('payment_simulator');

        self::assertSame('paid', $this->orderStatus($orderId));
        self::assertSame(['succeeded', null], $this->payment($paymentId));
        self::assertSame(1, $this->orderPaidEvents($orderId));
        self::assertSame(0, $this->rows('payment_simulator'));
        self::assertSame(0, $this->rows('failed'));
    }

    public function testAPaymentExecutedBeforeTheDeadlineAndReceivedThreeMinutesAfterItPaysTheOrder(): void
    {
        [$orderId, $paymentId, $transaction] = $this->paymentDecided(Provider::DemoCard);
        $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET decided_at = now() - interval '4 minutes' WHERE id = ?", [$transaction]);
        $this->connection()->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '3 minutes' WHERE id = ?", [$orderId]);
        $this->entityManager->clear();

        $this->consume('payment_simulator');

        self::assertSame('paid', $this->orderStatus($orderId));
        self::assertSame(['succeeded', null], $this->payment($paymentId));
        self::assertSame(1, $this->orderPaidEvents($orderId));
    }

    public function testAPaymentExecutedAfterTheDeadlineIsKeptForARefundAndTheOrderStaysNew(): void
    {
        [$orderId, $paymentId, $transaction] = $this->paymentDecided(Provider::DemoTransfer);
        $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET decided_at = now() - interval '1 minute' WHERE id = ?", [$transaction]);
        $this->connection()->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '2 minutes' WHERE id = ?", [$orderId]);
        $this->entityManager->clear();

        $this->consume('payment_simulator');

        self::assertSame('new', $this->orderStatus($orderId), 'cancelled later, after the window of the webhooks');
        self::assertSame(['succeeded', 'paid_after_deadline'], $this->payment($paymentId));
        self::assertSame(0, $this->orderPaidEvents($orderId));
        self::assertSame(0, $this->rows('payment_simulator'), 'the delivery ended without retries');
        self::assertSame(0, $this->rows('failed'));
    }

    public function testASuccessForACancelledOrderIsKeptForARefundAndTheOrderStaysCancelledWithItsReservationReleased(): void
    {
        [$orderId, $paymentId, $transaction, $variant] = $this->paymentDecided(Provider::DemoTransfer);
        // The deadline and the window after it have passed: the delayed ExpireUnpaidOrder of the placement cancels it.
        $this->connection()->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '6 minutes' WHERE id = ?", [$orderId]);
        $this->connection()->executeStatement("UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - interval '1 second' WHERE queue_name = 'scheduled'");
        $this->entityManager->clear();
        $this->consume('scheduled');
        self::assertSame('cancelled', $this->orderStatus($orderId));
        self::assertSame('released', $this->reservationStatus($orderId));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection(), $variant));
        $this->entityManager->clear();

        $this->consume('payment_simulator');

        self::assertSame(['succeeded', 'order_cancelled'], $this->payment($paymentId));
        self::assertSame('cancelled', $this->orderStatus($orderId));
        self::assertSame('released', $this->reservationStatus($orderId));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection(), $variant));
        self::assertSame(0, $this->orderPaidEvents($orderId));
        self::assertSame(0, $this->rows('payment_simulator'), 'the delivery ended with a 204, without retries');
        self::assertSame(0, $this->rows('failed'));

        $before = $this->snapshot($orderId, $variant);
        $this->bus->dispatch(new DeliverSimulatedWebhook($transaction));
        $this->consume('payment_simulator');

        self::assertSame($before, $this->snapshot($orderId, $variant), 'the repeated webhook changes nothing');
        self::assertSame(0, $this->rows('payment_simulator'));
    }

    /**
     * A guest order (one unit of a variant with 10 in stock), a payment started at the provider and paid there by the
     * customer; the delivery of its webhook waits in `payment_simulator`.
     *
     * @return array{string, string, string, string} the order, the payment, the transaction of the simulator and the
     *                                               variant
     */
    private function paymentDecided(Provider $provider): array
    {
        [$placement, $variant] = PaymentFixture::placement($this->connection());
        $order = PaymentFixture::placed($this->bus->dispatch($placement)->last(HandledStamp::class)?->getResult())->order;
        self::assertNotNull($order->accessToken);
        $started = $this->bus->dispatch(new StartPayment($order->orderId, PaymentAccess::guest($order->accessToken), $provider->value, 'flow-'.$order->orderId))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StartedPayment::class, $started);
        $transaction = $this->connection()->fetchOne('SELECT provider_payment_id FROM payment_payment WHERE id = ?', [$started->payment->paymentId]);
        self::assertIsString($transaction);
        self::assertSame(0, $this->rows('payment_simulator'));

        $this->bus->dispatch(new DecideTransaction($transaction, $provider, true));
        self::assertSame(1, $this->rows('payment_simulator'));

        return [$order->orderId, $started->payment->paymentId, $transaction, $variant];
    }

    private function orderStatus(string $orderId): string
    {
        $status = $this->connection()->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]);
        self::assertIsString($status);

        return $status;
    }

    /**
     * @return array{string, ?string} the status and the refund reason
     */
    private function payment(string $paymentId): array
    {
        $row = $this->connection()->fetchNumeric('SELECT status, refund_required_reason FROM payment_payment WHERE id = ?', [$paymentId]);
        \assert(\is_array($row) && \is_string($row[0]) && (null === $row[1] || \is_string($row[1])));

        return [$row[0], $row[1]];
    }

    private function reservationStatus(string $orderId): mixed
    {
        return $this->connection()->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]);
    }

    private function webhookEvents(string $paymentId): int
    {
        return OrderingFixture::count($this->connection(), 'SELECT COUNT(*) FROM payment_webhook_event WHERE payment_id = ?', [$paymentId]);
    }

    private function orderPaidEvents(string $orderId): int
    {
        return OrderingFixture::count(
            $this->connection(),
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0 AND position(? IN body) > 0",
            // The body escapes the backslashes of the class name.
            [addslashes(OrderPaid::class), $orderId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $orderId, string $variant): array
    {
        return [
            'order' => $this->connection()->fetchAssociative('SELECT status, payment_id, cancelled_at, version FROM ordering_order WHERE id = ?', [$orderId]),
            'payments' => $this->connection()->fetchAllAssociative('SELECT id, status, completed_at, executed_at, refund_required_reason FROM payment_payment WHERE order_id = ?', [$orderId]),
            'events' => $this->connection()->fetchAllAssociative('SELECT provider, event_id, received_at FROM payment_webhook_event ORDER BY event_id'),
            'reservation' => $this->reservationStatus($orderId),
            'stock' => OrderingFixture::stock($this->connection(), $variant),
            'outbox' => $this->rows('outbox'),
        ];
    }

    private function elapseRetryDelay(): void
    {
        $this->connection()->executeStatement("UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - interval '1 second' WHERE queue_name = 'payment_simulator'");
    }
}
