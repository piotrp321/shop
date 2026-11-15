<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * MarkOrderPaid on command.bus with the real adapters, in the transaction DAMA rolls back after each test: only the
 * result Paid adds the order to the sales of the hour of its payment, in the transaction of the command, so the same
 * payment again (AlreadyPaid), another payment, a payment after the deadline and a transaction rolled back add nothing.
 */
final class SalesFromPaymentsTest extends KernelTestCase
{
    private Connection $connection;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->connection = $connection;
        $this->bus = $bus;
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
    }

    public function testAPaidOrderIsAddedOnceAndTheSamePaymentAgainOrAnotherPaymentAddNothing(): void
    {
        $orderId = OrderingFixture::order($this->connection);
        $payment = Uuid::v7()->toRfc4122();

        self::assertSame(MarkOrderPaidResult::Paid, $this->pay($orderId, $payment));
        self::assertSame(MarkOrderPaidResult::AlreadyPaid, $this->pay($orderId, $payment));
        self::assertSame(MarkOrderPaidResult::PaidByAnotherPayment, $this->pay($orderId, Uuid::v7()->toRfc4122()));

        $hour = $this->connection->fetchOne("SELECT date_bin('1 hour', paid_at, TIMESTAMPTZ '2000-01-01 00:00:00+00') = hour_start FROM ordering_order, ordering_sales_hour WHERE ordering_order.id = ?", [$orderId]);
        self::assertTrue($hour, 'the row is the hour of the payment');
        self::assertSame([[1, 1_230]], $this->totals());
    }

    public function testAPaymentAfterTheDeadlineAddsNothing(): void
    {
        $orderId = OrderingFixture::order($this->connection);

        self::assertSame(MarkOrderPaidResult::PaidAfterDeadline, $this->pay($orderId, Uuid::v7()->toRfc4122(), new \DateTimeImmutable('+1 hour')));

        self::assertSame([[0, 0]], $this->totals());
    }

    public function testAPaymentInATransactionRolledBackAfterItAddsNothing(): void
    {
        $orderId = OrderingFixture::order($this->connection);

        // As the webhook of Payment: MarkOrderPaid nested in its transaction, which then fails.
        $this->connection->beginTransaction();
        self::assertSame(MarkOrderPaidResult::Paid, $this->pay($orderId, Uuid::v7()->toRfc4122()));
        self::assertSame([[1, 1_230]], $this->totals());
        $this->connection->rollBack();

        self::assertSame([[0, 0]], $this->totals());
        self::assertSame('new', $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
    }

    private function pay(string $orderId, string $paymentId, ?\DateTimeImmutable $executedAt = null): MarkOrderPaidResult
    {
        $result = $this->bus->dispatch(new MarkOrderPaid($orderId, $paymentId, 1_230, 'PLN', $executedAt ?? new \DateTimeImmutable()))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(MarkOrderPaidResult::class, $result);

        return $result;
    }

    /**
     * @return list<array{int, int}>
     */
    private function totals(): array
    {
        /** @var list<array{int, int}> $rows */
        $rows = $this->connection->fetchAllNumeric('SELECT CAST(COALESCE(SUM(order_count), 0) AS int), CAST(COALESCE(SUM(gross), 0) AS int) FROM ordering_sales_hour');

        return $rows;
    }
}
