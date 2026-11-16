<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Query\GetOrderQueues;
use App\Ordering\Application\Query\GetOrderQueuesHandler;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalBackofficeOrderReader;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\RecordingConnection;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The queues of the dashboard on 100 000 orders, with the plan as the planner chooses it on its own statistics (after
 * ANALYZE), not steered by switches: no queue scans the table, every one is read through an index and stops at its
 * limit of 1000 rows.
 *
 * The orders are in a temporary table that takes the place of ordering_order for the connection of the test (a copy of
 * its definition with its indexes), so the table of the test database is not filled with rows that the rollback leaves
 * behind as garbage.
 */
final class OrderQueuesPlanTest extends KernelTestCase
{
    private const int ORDERS = 100_000;
    private const string NOW = '2026-02-01 00:00:00+00:00';

    private Connection $connection;
    private RecordingConnection $recording;
    private GetOrderQueuesHandler $queues;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE ordering_order (LIKE public.ordering_order INCLUDING ALL)');
        $this->fill();
        $this->connection->executeStatement('ANALYZE ordering_order');
        $this->recording = RecordingConnection::like($this->connection);
        $this->queues = new GetOrderQueuesHandler(
            new DbalBackofficeOrderReader($this->recording, new BoundedCounter($this->recording)),
            new FixedOrderingClock(new \DateTimeImmutable(self::NOW)),
        );
    }

    /**
     * One test for all of them, so the 100 000 orders are written and analysed once.
     */
    public function testNoQueueScansTheTableAndEveryOneStopsAtItsLimit(): void
    {
        self::assertSame(self::ORDERS, $this->connection->fetchOne('SELECT count(*) FROM ordering_order'));
        // paid: 2 000 (capped), new: 2 000 (capped); refunds: 100, of them 50 pending, of these due before the clock: 15.
        $expected = [
            'paid to ship' => [new GetOrderQueues(true, false, false, false), 1000, true],
            'awaiting payment' => [new GetOrderQueues(false, true, false, false), 1000, true],
            'pending refunds' => [new GetOrderQueues(false, false, true, false), 50, false],
            'overdue refunds' => [new GetOrderQueues(false, false, false, true), 15, false],
        ];

        foreach ($expected as $label => [$query, $value, $capped]) {
            $queues = ($this->queues)($query);
            $count = $queues->paidToShip ?? $queues->awaitingPayment ?? $queues->pendingRefunds ?? $queues->overdueRefunds;
            $plan = $this->recording->planOfTheLastQuery($this->connection, false);

            self::assertNotNull($count, $label);
            self::assertSame([$value, $capped], [$count->value, $count->capped], $label);
            self::assertDoesNotMatchRegularExpression('/Seq Scan on ordering_order(?![_a-z])/', $plan, $label."\n".$plan);
            self::assertMatchesRegularExpression('/Index (Only )?Scan|Bitmap Index Scan/', $plan, $label."\n".$plan);
        }
    }

    /**
     * Paid and new orders in fifties (a few of all, as they leave these statuses quickly), the rest delivered, and a returned order with a refund for every thousandth: every other of
     * them is completed. The refunds are due in the order of their number, a day apart from the first of January.
     */
    private function fill(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ordering_order (
                    id, number, status, owner_type, customer_id, access_token, email, full_name, phone, street, postal_code, city, country, currency,
                    total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline,
                    paid_at, payment_id, shipped_at, delivered_at, returned_at, refund_amount, refund_due_at,
                    refund_completed_at, refund_completed_by_staff_id, refund_completed_by_username, version
                )
                SELECT gen_random_uuid(), 'R-'||(100000 + n), s.status, 'guest', NULL, substr(md5('t'||n)||md5('u'||n), 1, 43),
                       substr(md5('e'||n), 1, 12)||'@example.com', 'Jan Kowalski',
                       '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 0, 1000,
                       'plan-'||n, repeat('a', 64), timestamptz '2026-01-01 00:00:00+00' + n * interval '5 minutes',
                       timestamptz '2026-01-01 00:30:00+00' + n * interval '5 minutes',
                       CASE WHEN s.status = 'new' THEN NULL ELSE timestamptz '2026-01-01 00:05:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status = 'new' THEN NULL ELSE gen_random_uuid() END,
                       CASE WHEN s.status IN ('delivered', 'returned') THEN timestamptz '2026-01-01 01:00:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status IN ('delivered', 'returned') THEN timestamptz '2026-01-01 02:00:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status = 'returned' THEN timestamptz '2026-01-01 03:00:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status = 'returned' THEN 1000 END,
                       CASE WHEN s.status = 'returned' THEN timestamptz '2026-01-01 00:00:00+00' + (n / 1000) * interval '1 day' END,
                       CASE WHEN s.status = 'returned' AND n % 2000 = 5 THEN timestamptz '2026-01-01 04:00:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status = 'returned' AND n % 2000 = 5 THEN 'staff-1' END,
                       CASE WHEN s.status = 'returned' AND n % 2000 = 5 THEN 'anna' END,
                       1
                FROM generate_series(1, :orders) AS n
                CROSS JOIN LATERAL (SELECT CASE WHEN n % 1000 = 5 THEN 'returned' WHEN n % 50 = 0 THEN 'paid' WHEN n % 50 = 1 THEN 'new' ELSE 'delivered' END AS status) s
                SQL,
            ['orders' => self::ORDERS],
        );
    }
}
