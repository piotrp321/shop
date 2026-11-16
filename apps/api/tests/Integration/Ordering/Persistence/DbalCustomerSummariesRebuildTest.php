<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\CustomerRebuildTotals;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalCustomerSummaries;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rebuild of the customer summaries on PostgreSQL, in the transaction DAMA rolls back after each test (a batch
 * runs in a savepoint of it): a batch rewrites the summaries of its customers from their orders, deletes those
 * without orders, touches nothing outside its range, ignores the orders of guests and writes what the placements and
 * payments write. shop_test has no committed orders of customers, so the batches see only the orders of the test.
 */
final class DbalCustomerSummariesRebuildTest extends KernelTestCase
{
    private Connection $connection;
    private DbalCustomerSummaries $customers;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->customers = new DbalCustomerSummaries($connection);
        $this->connection->executeStatement('DELETE FROM ordering_customer');
        self::assertSame(0, OrderingFixture::count($connection, "SELECT COUNT(*) FROM ordering_order WHERE owner_type = 'customer'"), 'shop_test has no orders of customers of its own');
    }

    public function testABatchWritesTheSummariesOfItsCustomersFromTheirOrders(): void
    {
        OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: 'kc-a', fullName: 'Anna Nowak', placedAt: '2031-05-01 10:00:00+00:00', paid: true);
        $newest = OrderingFixture::order($this->connection, email: 'anna.k@example.com', customerId: 'kc-a', fullName: 'Anna Kowalska', placedAt: '2031-05-03 10:00:00+00:00', phone: '+48700100200');
        OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: 'kc-a', fullName: 'Anna Nowak', placedAt: '2031-05-02 10:00:00+00:00', paid: true);
        $only = OrderingFixture::order($this->connection, email: 'bartek@example.com', customerId: 'kc-b', fullName: 'Bartek Lis', placedAt: '2031-05-02 11:00:00+00:00');
        OrderingFixture::order($this->connection, email: 'gosc@example.com', placedAt: '2031-05-04 10:00:00+00:00', paid: true);

        $totals = $this->customers->rebuild(null, 'kc-b');

        self::assertEquals(new CustomerRebuildTotals(2, 4), $totals);
        self::assertSame([
            ['kc-a', 'anna.k@example.com', 'Anna Kowalska', '+48700100200', 3, 2, 2_460, '2031-05-01 10:00:00', '2031-05-03 10:00:00', $newest],
            ['kc-b', 'bartek@example.com', 'Bartek Lis', '+48600100200', 1, 0, 0, '2031-05-02 11:00:00', '2031-05-02 11:00:00', $only],
        ], $this->rows());
    }

    public function testABatchRewritesAStaleSummaryAndDeletesTheOneOfACustomerWithoutOrders(): void
    {
        $order = OrderingFixture::order($this->connection, email: 'anna@example.com', customerId: 'kc-a', fullName: 'Anna Nowak', placedAt: '2031-05-01 10:00:00+00:00');
        $this->summary('kc-a', 7, 5, 99_999);
        $this->summary('kc-ghost', 2, 1, 1_230);

        $this->customers->rebuild(null, 'kc-z');

        self::assertSame([['kc-a', 'anna@example.com', 'Anna Nowak', '+48600100200', 1, 0, 0, '2031-05-01 10:00:00', '2031-05-01 10:00:00', $order]], $this->rows());
    }

    public function testABatchTouchesOnlyTheCustomersAfterItsStartUpToItsLast(): void
    {
        foreach (['kc-a', 'kc-b', 'kc-c', 'kc-d'] as $customer) {
            OrderingFixture::order($this->connection, customerId: $customer, placedAt: '2031-05-01 10:00:00+00:00');
            $this->summary($customer, 9, 0, 0);
        }

        self::assertEquals(new CustomerRebuildTotals(2, 2), $this->customers->rebuild('kc-a', 'kc-c'));

        self::assertSame(['kc-a' => 9, 'kc-b' => 1, 'kc-c' => 1, 'kc-d' => 9], $this->connection->fetchAllKeyValue('SELECT customer_id, order_count FROM ordering_customer ORDER BY customer_id'));
    }

    public function testASecondRebuildLeavesTheSameRows(): void
    {
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2031-05-01 10:00:00+00:00', paid: true);
        OrderingFixture::order($this->connection, customerId: 'kc-b', placedAt: '2031-05-01 11:00:00+00:00');
        $this->customers->rebuild(null, 'kc-b');
        $first = $this->rows();

        $this->customers->rebuild(null, 'kc-b');

        self::assertSame($first, $this->rows());
    }

    public function testTheRebuildWritesWhatThePlacementsAndPaymentsWrote(): void
    {
        foreach ([
            ['kc-a', 'a1@example.com', '2031-05-01 10:00:00+00:00', true],
            ['kc-a', 'a2@example.com', '2031-05-01 10:00:00+00:00', false],
            ['kc-a', 'a3@example.com', '2031-04-30 10:00:00+00:00', true],
            ['kc-b', 'b1@example.com', '2031-05-02 10:00:00+00:00', true],
        ] as [$customer, $email, $placedAt, $paid]) {
            $id = OrderingFixture::order($this->connection, email: $email, customerId: $customer, placedAt: $placedAt);
            $this->customers->addOrder($customer, OrderId::fromString($id), Contact::of(EmailAddress::fromString($email), 'Jan Kowalski', '+48600100200'), new \DateTimeImmutable($placedAt));
            if ($paid) {
                $this->connection->executeStatement("UPDATE ordering_order SET status = 'paid', paid_at = placed_at, payment_id = gen_random_uuid() WHERE id = ?", [$id]);
                $this->customers->addPayment($customer, OrderId::fromString($id), 1_230);
            }
        }
        $incremental = $this->rows();

        $this->customers->rebuild(null, 'kc-b');

        self::assertSame($incremental, $this->rows());
    }

    public function testTheNextBatchEndsAtTheLastOfTheNextCustomersWithOrdersOrASummary(): void
    {
        foreach (['kc-a', 'kc-c', 'kc-e'] as $customer) {
            OrderingFixture::order($this->connection, customerId: $customer);
            OrderingFixture::order($this->connection, customerId: $customer);
        }
        $this->summary('kc-b', 1, 0, 0);
        $this->summary('kc-d', 1, 0, 0);
        OrderingFixture::order($this->connection, email: 'gosc@example.com');

        self::assertSame('kc-b', $this->customers->nextBatchEnd(null, 2));
        self::assertSame('kc-d', $this->customers->nextBatchEnd('kc-b', 2));
        self::assertSame('kc-e', $this->customers->nextBatchEnd('kc-d', 2));
        self::assertSame('kc-e', $this->customers->nextBatchEnd(null, 500));
        self::assertNull($this->customers->nextBatchEnd('kc-e', 2));
    }

    private function summary(string $customer, int $orders, int $paid, int $gross): void
    {
        $this->connection->executeStatement(
            "INSERT INTO ordering_customer (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
             VALUES (?, 'stale@example.com', 'Stary', '+48600000000', ?, ?, ?, '2030-01-01 00:00:00+00', '2030-01-02 00:00:00+00', gen_random_uuid())",
            [$customer, $orders, $paid, $gross],
        );
    }

    /**
     * @return list<list<mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllNumeric(
            "SELECT customer_id, email, full_name, phone, order_count, paid_count, CAST(paid_gross AS int),
                    to_char(first_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), to_char(last_order_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'),
                    CAST(last_order_id AS text)
             FROM ordering_customer ORDER BY customer_id",
        );
    }
}
