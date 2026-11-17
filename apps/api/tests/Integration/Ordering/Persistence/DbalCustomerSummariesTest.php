<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalCustomerSummaries;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The customer summaries on PostgreSQL, in the transaction DAMA rolls back after each test: an order counts and gives
 * its contact when it is the newest one of the customer (by the moment of placement, then by the order id), a payment
 * counts the order as paid with its gross total, and a payment of a customer without a summary (an order placed before
 * the rollout, before the backfill) makes one from the order itself, so the row breaks no constraint.
 */
final class DbalCustomerSummariesTest extends KernelTestCase
{
    private const string CUSTOMER = 'kc-summary-1';
    private const string FIRST = '01970000-0000-7000-8000-000000000001';
    private const string SECOND = '01970000-0000-7000-8000-000000000002';
    private const string THIRD = '01970000-0000-7000-8000-000000000003';

    private Connection $connection;
    private DbalCustomerSummaries $customers;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->customers = new DbalCustomerSummaries($connection);
        $this->connection->executeStatement('DELETE FROM ordering_customer');
    }

    public function testTheFirstOrderOfACustomerMakesItsSummary(): void
    {
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::FIRST), self::contact('anna@example.com', 'Anna Nowak', '600100200'), self::at('2031-05-04 12:00:00+00:00'));

        self::assertSame([[
            'customer_id' => self::CUSTOMER,
            'email' => 'anna@example.com',
            'full_name' => 'Anna Nowak',
            'phone' => '+48600100200',
            'order_count' => 1,
            'paid_count' => 0,
            'paid_gross' => 0,
            'first_order_at' => '2031-05-04 12:00:00+00',
            'last_order_at' => '2031-05-04 12:00:00+00',
            'last_order_id' => self::FIRST,
        ]], $this->rows());
    }

    public function testANewerOrderCountsAndGivesItsContact(): void
    {
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::FIRST), self::contact('anna@example.com', 'Anna Nowak', '600100200'), self::at('2031-05-04 12:00:00+00:00'));
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::SECOND), self::contact('anna.k@example.com', 'Anna Kowalska', '700100200'), self::at('2031-05-05 08:00:00+00:00'));

        self::assertSame(
            [self::CUSTOMER, 'anna.k@example.com', 'Anna Kowalska', '+48700100200', 2, 0, 0, '2031-05-04 12:00:00+00', '2031-05-05 08:00:00+00', self::SECOND],
            array_values($this->rows()[0]),
        );
    }

    public function testAnOlderOrderCountsAndMovesTheFirstOrderButKeepsTheContactOfTheNewest(): void
    {
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::SECOND), self::contact('anna.k@example.com', 'Anna Kowalska', '700100200'), self::at('2031-05-05 08:00:00+00:00'));
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::FIRST), self::contact('anna@example.com', 'Anna Nowak', '600100200'), self::at('2031-05-04 12:00:00+00:00'));

        self::assertSame(
            [self::CUSTOMER, 'anna.k@example.com', 'Anna Kowalska', '+48700100200', 2, 0, 0, '2031-05-04 12:00:00+00', '2031-05-05 08:00:00+00', self::SECOND],
            array_values($this->rows()[0]),
        );
    }

    public function testOfTwoOrdersOfOneMomentTheHigherIdGivesTheContactWhicheverComesFirst(): void
    {
        $at = self::at('2031-05-04 12:00:00+00:00');
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::THIRD), self::contact('third@example.com', 'Trzecia', '600100203'), $at);
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::SECOND), self::contact('second@example.com', 'Druga', '600100202'), $at);

        self::assertSame(['third@example.com', 2, self::THIRD], [$this->rows()[0]['email'], $this->rows()[0]['order_count'], $this->rows()[0]['last_order_id']]);
    }

    public function testAPaymentCountsTheOrderAsPaidWithItsGrossTotalAndKeepsTheRest(): void
    {
        // The orders are there when they are paid, as MarkOrderPaid pays an order placed before.
        foreach ([self::FIRST => '2031-05-04 12:00:00+00:00', self::SECOND => '2031-05-04 13:00:00+00:00'] as $id => $placedAt) {
            OrderingFixture::order($this->connection, email: 'anna@example.com', id: $id, customerId: self::CUSTOMER, fullName: 'Anna Nowak', placedAt: $placedAt);
            $this->customers->addOrder(self::CUSTOMER, OrderId::fromString($id), self::contact('anna@example.com', 'Anna Nowak', '600100200'), self::at($placedAt));
        }

        $this->customers->addPayment(self::CUSTOMER, OrderId::fromString(self::FIRST), 12_300);
        $this->customers->addPayment(self::CUSTOMER, OrderId::fromString(self::SECOND), 700);

        $row = $this->rows()[0];
        self::assertSame([2, 2, 13_000, '2031-05-04 13:00:00+00', self::SECOND], [$row['order_count'], $row['paid_count'], $row['paid_gross'], $row['last_order_at'], $row['last_order_id']]);
    }

    public function testAPaymentOfACustomerWithoutASummaryMakesOneFromItsOrder(): void
    {
        // Placed before the rollout of the customers: the summary is made by the backfill, the payment comes first.
        $orderId = OrderingFixture::order($this->connection, email: 'ola@example.com', customerId: self::CUSTOMER, fullName: 'Ola Lis', placedAt: '2031-05-01 09:00:00+00:00', phone: '+48500100200');

        $this->customers->addPayment(self::CUSTOMER, OrderId::fromString($orderId), 1_230);

        self::assertSame([[
            'customer_id' => self::CUSTOMER,
            'email' => 'ola@example.com',
            'full_name' => 'Ola Lis',
            'phone' => '+48500100200',
            'order_count' => 1,
            'paid_count' => 1,
            'paid_gross' => 1_230,
            'first_order_at' => '2031-05-01 09:00:00+00',
            'last_order_at' => '2031-05-01 09:00:00+00',
            'last_order_id' => $orderId,
        ]], $this->rows());
    }

    public function testTheAdditionsOfATransactionThatRollsBackLeaveNothing(): void
    {
        OrderingFixture::order($this->connection, id: self::FIRST, customerId: self::CUSTOMER);
        $this->connection->beginTransaction();
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::FIRST), self::contact('anna@example.com', 'Anna Nowak', '600100200'), self::at('2031-05-04 12:00:00+00:00'));
        $this->customers->addPayment(self::CUSTOMER, OrderId::fromString(self::FIRST), 12_300);
        self::assertCount(1, $this->rows());
        $this->connection->rollBack();

        self::assertSame([], $this->rows());
    }

    public function testPolishLettersOfTheContactAreKept(): void
    {
        $this->customers->addOrder(self::CUSTOMER, OrderId::fromString(self::FIRST), self::contact('zażółć@example.com', 'Łucja Gęślą-Jaźń', '600100200'), self::at('2031-05-04 12:00:00+00:00'));

        self::assertSame(['zażółć@example.com', 'Łucja Gęślą-Jaźń'], [$this->rows()[0]['email'], $this->rows()[0]['full_name']]);
    }

    private static function contact(string $email, string $fullName, string $phone): Contact
    {
        return Contact::of(EmailAddress::fromString($email), $fullName, $phone);
    }

    private static function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT customer_id, email, full_name, phone, order_count, paid_count, CAST(paid_gross AS int) AS paid_gross,
                    to_char(first_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00') AS first_order_at,
                    to_char(last_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00') AS last_order_at,
                    CAST(last_order_id AS text) AS last_order_id
             FROM ordering_customer ORDER BY customer_id",
        );
    }
}
