<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Application\AbandonedPayments;
use App\Payment\Application\Command\HandleProviderWebhook;
use App\Payment\Application\Command\StartedPayment;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\Port\PayableOrders;
use App\Payment\Application\ProviderEvent;
use App\Payment\Domain\Amount;
use App\Payment\Domain\Currency;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;
use App\Payment\Infrastructure\Ordering\QueryBusPayableOrders;
use App\Payment\Infrastructure\Persistence\Doctrine\DbalPendingPayments;
use App\Payment\UI\Console\CloseAbandonedPaymentsCommand;
use App\Tests\Integration\Payment\PaymentFixture;
use App\Tests\Support\RecordingLogProcessor;
use App\Tests\Unit\Ordering\SpyLogger;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * payment:close-abandoned (spec: docs/features/async-reliability/spec.md, D5, W6, criterion 6) on shop_test against the
 * simulator (KernelForwardingHttpClient, the same transaction of DAMA): the pending payment of an order Ordering no
 * longer lets pay is closed as its provider answers the cancellation of the session, a session paid at the provider is
 * left for its webhook, and the payment of a payable order is left as it is.
 */
final class CloseAbandonedPaymentsCommandTest extends KernelTestCase
{
    private Connection $connection;
    private MessageBusInterface $commandBus;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $commandBus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $commandBus);
        $this->connection = $connection;
        $this->commandBus = $commandBus;
        $logs = self::getContainer()->get(RecordingLogProcessor::class);
        self::assertInstanceOf(RecordingLogProcessor::class, $logs);
        $logs->clear();
    }

    public function testThePendingPaymentsOfCancelledAndOverdueOrdersAreCancelledAndOfAPayableOneLeft(): void
    {
        [$cancelledOrder, $cancelled] = $this->pendingPayment();
        $this->cancelOrder($cancelledOrder);
        [$overdueOrder, $overdue] = $this->pendingPayment();
        $this->passTheDeadline($overdueOrder);
        [, $payable] = $this->pendingPayment();
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        foreach ([$cancelled, $overdue] as $payment) {
            self::assertSame(['cancelled', true], $this->payment($payment), 'cancelled with the moment it ended');
            self::assertSame('cancelled', $this->session($payment), 'the session is cancelled at the provider');
        }
        self::assertSame(['pending', false], $this->payment($payable));
        self::assertSame('open', $this->session($payable));
        self::assertStringContainsString('cancelled: 2', $tester->getDisplay());
        self::assertStringContainsString('Closed 2 of 2 abandoned payments.', $tester->getDisplay());
    }

    public function testASessionTheProviderRejectedFailsThePayment(): void
    {
        [$orderId, $payment] = $this->pendingPayment();
        $this->cancelOrder($orderId);
        $this->decideAtTheProvider($payment, 'rejected');
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame(['failed', true], $this->payment($payment));
        self::assertStringContainsString('failed: 1', $tester->getDisplay());
    }

    public function testASessionPaidAtTheProviderStaysPendingWithAWarningOnEveryRun(): void
    {
        [$orderId, $payment] = $this->pendingPayment();
        $this->passTheDeadline($orderId);
        $this->decideAtTheProvider($payment, 'paid');
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertSame(['pending', false], $this->payment($payment));
        self::assertSame('paid', $this->session($payment));
        self::assertStringContainsString('paid at the provider, left for the webhook: 1', $tester->getDisplay());
        $warnings = array_values(array_filter($this->logs(), static fn (string $record): bool => str_contains($record, 'paid at its provider') && str_contains($record, $payment->payment->paymentId)));
        self::assertCount(2, $warnings, 'one warning per run');
    }

    public function testALateWebhookOfSuccessAfterTheClosingSucceedsThePaymentWithARefund(): void
    {
        [$orderId, $payment] = $this->pendingPayment();
        $this->cancelOrder($orderId);
        self::assertSame(0, $this->tester()->execute([]));
        self::assertSame(['cancelled', true], $this->payment($payment));

        $this->commandBus->dispatch(new HandleProviderWebhook(ProviderCode::DemoTransfer, ProviderEvent::succeeded(
            ProviderEventId::fromString('evt_late_'.substr($payment->payment->paymentId, -12)),
            ProviderPaymentId::fromString($this->sessionId($payment)),
            Amount::of(PaymentFixture::GROSS, Currency::PLN),
            new \DateTimeImmutable('-1 minute'),
        )));

        self::assertSame(['succeeded', 'order_cancelled'], $this->connection->fetchNumeric('SELECT status, refund_required_reason FROM payment_payment WHERE id = ?', [$payment->payment->paymentId]));
    }

    public function testAnUnavailableProviderLeavesItsPaymentClosesTheOthersAndEndsWithTwo(): void
    {
        [$firstOrder, $first] = $this->pendingPayment();
        [$brokenOrder, $broken] = $this->pendingPayment();
        [$lastOrder, $last] = $this->pendingPayment();
        foreach ([$firstOrder, $brokenOrder, $lastOrder] as $orderId) {
            $this->cancelOrder($orderId);
        }
        // The simulator does not know the session: it answers 404, which the adapter reports as an unavailable provider.
        $this->connection->executeStatement('UPDATE payment_payment SET provider_payment_id = ? WHERE id = ?', [Uuid::v4()->toRfc4122(), $broken->payment->paymentId]);
        $logger = new SpyLogger();
        $tester = new CommandTester($this->command($logger));

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());

        self::assertSame(['pending', false], $this->payment($broken));
        self::assertSame(['cancelled', true], $this->payment($first));
        self::assertSame(['cancelled', true], $this->payment($last));
        self::assertStringContainsString('Closed 2 of 3 abandoned payments; 1 could not be closed', $tester->getDisplay());
        $errors = array_values(array_filter($logger->records, static fn (array $record): bool => 'error' === $record[0]));
        self::assertCount(1, $errors);
        self::assertSame($broken->payment->paymentId, $errors[0][2]['paymentId'] ?? null);
        self::assertSame($brokenOrder, $errors[0][2]['orderId'] ?? null);
        self::assertInstanceOf(\Throwable::class, $errors[0][2]['exception'] ?? null);
    }

    /**
     * The write of one payment fails in the database, which closes the entity manager; the command replaces it, so the
     * next payment is closed.
     */
    public function testAFailedWriteOfOnePaymentDoesNotStopTheNext(): void
    {
        [$firstOrder, $first] = $this->pendingPayment();
        [$secondOrder, $second] = $this->pendingPayment();
        $this->cancelOrder($firstOrder);
        $this->cancelOrder($secondOrder);
        $failing = $firstOrder < $secondOrder ? $first : $second;
        $closed = $failing === $first ? $second : $first;
        $this->connection->executeStatement("CREATE FUNCTION payment_test_fail_closing() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of a closing'; END \$\$");
        $this->connection->executeStatement(\sprintf("CREATE TRIGGER payment_test_fail_closing BEFORE UPDATE ON payment_payment FOR EACH ROW WHEN (OLD.id = '%s'::uuid) EXECUTE FUNCTION payment_test_fail_closing()", $failing->payment->paymentId));
        $logger = new SpyLogger();
        $tester = new CommandTester($this->command($logger));

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());

        self::assertSame(['pending', false], $this->payment($failing));
        self::assertSame(['cancelled', true], $this->payment($closed));
        self::assertSame($failing->payment->paymentId, $logger->records[0][2]['paymentId'] ?? null);
    }

    public function testOrderingIsAskedOncePerBatch(): void
    {
        $payments = [];
        for ($i = 0; $i < 3; ++$i) {
            [$orderId, $payments[]] = $this->pendingPayment();
            $this->cancelOrder($orderId);
        }
        $orders = new CountingPayableOrders($this->payableOrders());
        $tester = new CommandTester($this->command(new SpyLogger(), $orders));

        self::assertSame(0, $tester->execute(['--batch-size' => '2']), $tester->getDisplay());

        self::assertSame([2, 1], $orders->asked, 'one question per batch with the orders of the batch');
        foreach ($payments as $payment) {
            self::assertSame(['cancelled', true], $this->payment($payment));
        }
    }

    public function testNothingToCloseIsASuccess(): void
    {
        $this->pendingPayment();
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('No abandoned payments.', $tester->getDisplay());
    }

    public function testABatchSizeOutsideOneTo100IsRefusedWithoutChanges(): void
    {
        [$orderId, $payment] = $this->pendingPayment();
        $this->cancelOrder($orderId);
        $tester = $this->tester();

        foreach (['0', '-5', '101', 'many', '1.5', ''] as $size) {
            self::assertSame(1, $tester->execute(['--batch-size' => $size]), $size);
            self::assertStringContainsString('The batch size must be an integer from 1 to 100.', $tester->getDisplay());
        }
        self::assertSame(['pending', false], $this->payment($payment));
    }

    public function testAnUnavailableDatabaseStopsWithTwo(): void
    {
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        // Nothing listens there: every statement fails to connect, as when PostgreSQL is down.
        $unreachable = DriverManager::getConnection(['host' => '127.0.0.1', 'port' => 1] + $params, new DbalConfiguration());
        $application = new ConsoleApplication();
        $application->addCommand(new CloseAbandonedPaymentsCommand(new AbandonedPayments(new DbalPendingPayments($unreachable), $this->payableOrders()), $this->commandBus, $this->doctrine(), new SpyLogger()));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(2, $tester->run(['command' => 'payment:close-abandoned']), $tester->getDisplay());
        self::assertStringContainsString('The closing stopped:', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('payment:close-abandoned'));
    }

    private function command(SpyLogger $logger, ?PayableOrders $orders = null): CloseAbandonedPaymentsCommand
    {
        return new CloseAbandonedPaymentsCommand(new AbandonedPayments(new DbalPendingPayments($this->connection), $orders ?? $this->payableOrders()), $this->commandBus, $this->doctrine(), $logger);
    }

    private function payableOrders(): QueryBusPayableOrders
    {
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);

        return new QueryBusPayableOrders($queryBus);
    }

    private function doctrine(): ManagerRegistry
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    /**
     * @return array{string, StartedPayment} a new guest order and its pending payment with an open session at the
     *                                       simulator (demo-transfer)
     */
    private function pendingPayment(): array
    {
        [$placement] = PaymentFixture::placement($this->connection);
        $order = PaymentFixture::placed($this->commandBus->dispatch($placement)->last(HandledStamp::class)?->getResult())->order;
        self::assertNotNull($order->accessToken);
        $started = $this->commandBus->dispatch(new StartPayment($order->orderId, PaymentAccess::guest($order->accessToken), 'demo-transfer', 'close-'.Uuid::v7()->toRfc4122()))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(StartedPayment::class, $started);
        self::assertSame('pending', $started->payment->status);

        return [$order->orderId, $started];
    }

    private function cancelOrder(string $orderId): void
    {
        $this->connection->executeStatement("UPDATE ordering_order SET status = 'cancelled', cancelled_at = now(), cancellation_reason = 'payment_timeout' WHERE id = ?", [$orderId]);
    }

    private function passTheDeadline(string $orderId): void
    {
        $this->connection->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '1 second' WHERE id = ?", [$orderId]);
    }

    private function decideAtTheProvider(StartedPayment $payment, string $status): void
    {
        $this->connection->executeStatement('UPDATE payment_simulator_transaction SET status = ?, decided_at = now() WHERE id::text = ?', [$status, $this->sessionId($payment)]);
    }

    /**
     * @return array{string, bool} the status of the payment and whether it has the moment it ended
     */
    private function payment(StartedPayment $payment): array
    {
        $row = $this->connection->fetchNumeric('SELECT status, completed_at IS NOT NULL FROM payment_payment WHERE id = ?', [$payment->payment->paymentId]);
        self::assertIsArray($row);
        self::assertIsString($row[0]);
        self::assertIsBool($row[1]);

        return [$row[0], $row[1]];
    }

    private function sessionId(StartedPayment $payment): string
    {
        $session = $this->connection->fetchOne('SELECT provider_payment_id FROM payment_payment WHERE id = ?', [$payment->payment->paymentId]);
        self::assertIsString($session);

        return $session;
    }

    private function session(StartedPayment $payment): string
    {
        $status = $this->connection->fetchOne('SELECT status FROM payment_simulator_transaction WHERE id::text = ?', [$this->sessionId($payment)]);
        self::assertIsString($status);

        return $status;
    }

    /**
     * @return list<string>
     */
    private function logs(): array
    {
        $logs = self::getContainer()->get(RecordingLogProcessor::class);
        self::assertInstanceOf(RecordingLogProcessor::class, $logs);

        return $logs->records();
    }
}
