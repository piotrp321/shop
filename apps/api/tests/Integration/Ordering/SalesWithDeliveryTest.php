<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The summaries of an order with a delivery (docs/features/delivery/spec.md, W7 and P14) on shop_test, rolled back
 * after each test: its payment adds the goods without the delivery to the hourly sales and the whole total with the
 * delivery to the customer; ordering:read-models:rebuild gives the same numbers; a cancellation by staff or a return
 * changes neither.
 */
final class SalesWithDeliveryTest extends KernelTestCase
{
    private const string CUSTOMER = 'kc-sales-delivery';
    private const int GOODS = 1_230;
    private const int DELIVERY = 1_999;

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
        $this->connection->executeStatement('DELETE FROM ordering_customer');
    }

    public function testThePaymentAddsTheGoodsToTheSalesAndTheTotalToTheCustomerAndTheRebuildGivesTheSame(): void
    {
        $orderId = OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: self::CUSTOMER, deliveryGross: self::DELIVERY);

        self::assertSame(MarkOrderPaidResult::Paid, $this->pay($orderId, self::GOODS + self::DELIVERY));

        self::assertSame([1, self::GOODS], $this->sales(), 'the sales count the goods without the delivery');
        self::assertSame(self::GOODS + self::DELIVERY, $this->paidGrossOfTheCustomer(), 'the customer the whole total');

        $this->rebuild();
        self::assertSame([1, self::GOODS], $this->sales(), 'the rebuild gives the same sales');
        self::assertSame(self::GOODS + self::DELIVERY, $this->paidGrossOfTheCustomer(), 'and the same customer');
    }

    public function testACancellationByStaffAndAReturnChangeNeitherSummary(): void
    {
        $cancelled = OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: self::CUSTOMER, deliveryGross: self::DELIVERY);
        $returned = OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: self::CUSTOMER, deliveryGross: self::DELIVERY);
        self::assertSame(MarkOrderPaidResult::Paid, $this->pay($cancelled, self::GOODS + self::DELIVERY));
        self::assertSame(MarkOrderPaidResult::Paid, $this->pay($returned, self::GOODS + self::DELIVERY));

        $this->connection->executeStatement("UPDATE ordering_order SET status = 'cancelled', cancelled_at = now(), cancellation_reason = 'staff', refund_amount = total_gross, refund_due_at = now() + interval '14 days' WHERE id = ?", [$cancelled]);
        $this->connection->executeStatement("UPDATE ordering_order SET status = 'returned', shipped_at = now(), returned_at = now(), refund_amount = total_gross, refund_due_at = now() + interval '14 days' WHERE id = ?", [$returned]);
        $this->rebuild();

        self::assertSame([2, 2 * self::GOODS], $this->sales());
        self::assertSame(2 * (self::GOODS + self::DELIVERY), $this->paidGrossOfTheCustomer());
    }

    private function pay(string $orderId, int $amount): MarkOrderPaidResult
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $result = $bus->dispatch(new MarkOrderPaid($orderId, Uuid::v7()->toRfc4122(), $amount, 'PLN', new \DateTimeImmutable()))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(MarkOrderPaidResult::class, $result);

        return $result;
    }

    private function rebuild(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('ordering:read-models:rebuild'));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
    }

    /**
     * @return array{int, int} the orders and the gross of all the hourly sales
     */
    private function sales(): array
    {
        $row = $this->connection->fetchNumeric('SELECT CAST(COALESCE(SUM(order_count), 0) AS int), CAST(COALESCE(SUM(gross), 0) AS int) FROM ordering_sales_hour');
        \assert(\is_array($row) && \is_int($row[0]) && \is_int($row[1]));

        return [$row[0], $row[1]];
    }

    private function paidGrossOfTheCustomer(): int
    {
        $gross = $this->connection->fetchOne('SELECT paid_gross FROM ordering_customer WHERE customer_id = ?', [self::CUSTOMER]);
        \assert(\is_int($gross));

        return $gross;
    }
}
