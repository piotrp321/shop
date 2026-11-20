<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Ordering\Domain\OrderCancelled;
use App\Payment\Application\Command\StartedPayment;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\PaymentAccess;
use App\PaymentSimulator\DecideTransaction;
use App\PaymentSimulator\Provider;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Integration\Payment\PaymentFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The worker `worker-commands` of infra/docker-compose.yml and of the chart: `messenger:consume payment_simulator
 * scheduled promotions jobs`, which reads only Postgres. Spec: docs/features/async-reliability/spec.md, W1, criteria
 * 1 and 2.
 *
 * The first test runs it with the address of RabbitMQ on a closed port (a refused connection at once): the commands
 * are handled as without an outage, and the worker of the events, which needs the broker, fails. The second one
 * makes a webhook and the cancellation of the same order available together: the worker returns to the start of
 * its list after each message, so the webhook goes first and the order ends paid.
 */
final class CommandWorkerTest extends MessagingKernelTestCase
{
    private const array COMMANDS = ['payment_simulator', 'scheduled', 'promotions', 'jobs'];

    private ?string $rabbitMqHost = null;
    private bool $brokerBroken = false;

    protected function tearDown(): void
    {
        $this->restoreBroker();
        parent::tearDown();
    }

    public function testTheCommandsAreHandledWhileRabbitMqIsUnreachable(): void
    {
        $this->breakBroker();
        [$expired, $expiredVariant] = $this->orderWithExpiredPaymentWindow();
        [$paying] = $this->orderPaidAtTheProvider(expired: false);
        self::assertSame(2, $this->rows('scheduled'));
        self::assertSame(1, $this->rows('payment_simulator'));
        self::assertSame([10, 1], OrderingFixture::stock($this->connection(), $expiredVariant));

        $exit = $this->consumeCommands(2);

        self::assertSame(0, $exit);
        self::assertSame('cancelled', $this->orderStatus($expired));
        self::assertSame('released', $this->connection()->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$expired]));
        self::assertSame([10, 0], OrderingFixture::stock($this->connection(), $expiredVariant));
        self::assertSame(1, $this->outboxEvents(OrderCancelled::class, $expired));
        self::assertSame('paid', $this->orderStatus($paying));
        self::assertSame(0, $this->rows('payment_simulator'), 'DeliverSimulatedWebhook was handled');
        self::assertSame(0, $this->rows('failed'));
    }

    public function testTheWorkerOfTheEventsFailsWhileRabbitMqIsUnreachable(): void
    {
        $this->breakBroker();

        try {
            $exit = $this->runWorker(['outbox', 'events']);
        } catch (\Throwable $failure) {
            self::assertStringContainsString('AMQP', $failure->getMessage());

            return;
        }

        self::assertNotSame(0, $exit);
    }

    public function testAWebhookAvailableTogetherWithTheCancellationGoesFirstAndTheOrderEndsPaid(): void
    {
        [$orderId, $transaction] = $this->orderPaidAtTheProvider(expired: true);
        self::assertSame(1, $this->rows('payment_simulator'));
        self::assertSame(1, $this->rows('scheduled'));
        self::assertSame($transaction, $this->connection()->fetchOne('SELECT provider_payment_id FROM payment_payment WHERE order_id = ?', [$orderId]));

        $exit = $this->consumeCommands(2);

        self::assertSame(0, $exit);
        self::assertSame('paid', $this->orderStatus($orderId));
        self::assertSame('succeeded', $this->connection()->fetchOne('SELECT status FROM payment_payment WHERE order_id = ?', [$orderId]));
        self::assertSame(0, $this->outboxEvents(OrderCancelled::class, $orderId));
        self::assertSame(0, $this->rows('payment_simulator'));
        self::assertSame(0, $this->rows('scheduled'), 'the cancellation was handled after the webhook and found a paid order');
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * An order whose payment window and the 5 minutes of the cancellation have passed, with the cancellation due.
     *
     * @return array{string, string} the order and its variant
     */
    private function orderWithExpiredPaymentWindow(): array
    {
        [$placement, $variant] = PaymentFixture::placement($this->connection());
        $this->bus()->dispatch($placement);
        $orderId = $this->connection()->fetchOne('SELECT id FROM ordering_order ORDER BY placed_at DESC, id DESC LIMIT 1');
        self::assertIsString($orderId);
        $this->expireWindow($orderId);

        return [$orderId, $variant];
    }

    /**
     * A guest order, a payment started at the simulator and paid there by the customer (its webhook waits in
     * `payment_simulator`). With $expired the payment window and the 5 minutes of the cancellation have passed, the
     * cancellation is due, and the customer paid at the provider 1 minute before the end of the window.
     *
     * @return array{string, string} the order and the transaction of the simulator
     */
    private function orderPaidAtTheProvider(bool $expired): array
    {
        [$placement] = PaymentFixture::placement($this->connection());
        $order = PaymentFixture::placed($this->bus()->dispatch($placement)->last(HandledStamp::class)?->getResult())->order;
        self::assertNotNull($order->accessToken);
        $started = $this->bus()->dispatch(new StartPayment($order->orderId, PaymentAccess::guest($order->accessToken), Provider::DemoTransfer->value, 'worker-'.$order->orderId))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StartedPayment::class, $started);
        $transaction = $this->connection()->fetchOne('SELECT provider_payment_id FROM payment_payment WHERE id = ?', [$started->payment->paymentId]);
        self::assertIsString($transaction);
        $this->bus()->dispatch(new DecideTransaction($transaction, Provider::DemoTransfer, true));

        if ($expired) {
            $this->expireWindow($order->orderId);
            $this->connection()->executeStatement("UPDATE payment_simulator_transaction SET decided_at = now() - interval '7 minutes' WHERE id = ?", [$transaction]);
        }

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear(); // the updates above are SQL: forget the entities loaded before them

        return [$order->orderId, $transaction];
    }

    /**
     * The payment deadline 6 minutes ago (the cancellation is due 5 minutes after it) and the delayed message ready.
     */
    private function expireWindow(string $orderId): void
    {
        $this->connection()->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '6 minutes' WHERE id = ?", [$orderId]);
        $this->connection()->executeStatement(
            "UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - interval '1 second' WHERE queue_name = 'scheduled' AND position(? IN body) > 0",
            [$orderId],
        );
    }

    private function consumeCommands(int $limit): int
    {
        return $this->runWorker(self::COMMANDS, $limit);
    }

    /**
     * One `messenger:consume` process with the transports in the given order, ending after $limit messages or 10 s.
     *
     * @param list<string> $transports
     */
    private function runWorker(string|array $transports, int $limit = 1): int
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));

        return $tester->execute(['receivers' => (array) $transports, '--limit' => $limit, '--time-limit' => 10, '--sleep' => 0.05]);
    }

    private function orderStatus(string $orderId): string
    {
        $status = $this->connection()->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]);
        self::assertIsString($status);

        return $status;
    }

    private function outboxEvents(string $event, string $orderId): int
    {
        return OrderingFixture::count(
            $this->connection(),
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0 AND position(? IN body) > 0",
            // The body escapes the backslashes of the class name.
            [addslashes($event), $orderId],
        );
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /**
     * The address of RabbitMQ on a closed port of this host, in a freshly booted kernel.
     */
    private function breakBroker(): void
    {
        $host = $_SERVER['RABBITMQ_HOST'] ?? null;
        $this->rabbitMqHost = \is_string($host) ? $host : null;
        $this->brokerBroken = true;
        $_SERVER['RABBITMQ_HOST'] = $_ENV['RABBITMQ_HOST'] = '127.0.0.1';
        self::bootMessagingKernel();
    }

    private function restoreBroker(): void
    {
        if (!$this->brokerBroken) {
            return;
        }
        if (null === $this->rabbitMqHost) {
            unset($_SERVER['RABBITMQ_HOST'], $_ENV['RABBITMQ_HOST']);
        } else {
            $_SERVER['RABBITMQ_HOST'] = $_ENV['RABBITMQ_HOST'] = $this->rabbitMqHost;
        }
        $this->brokerBroken = false;
        self::bootMessagingKernel();
    }
}
