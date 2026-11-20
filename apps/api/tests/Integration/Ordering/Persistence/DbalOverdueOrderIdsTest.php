<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\OverdueOrder;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalOverdueOrderIds;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The candidates of ordering:expire-overdue (spec: docs/features/async-reliability/spec.md, W5) on 100 000 orders in
 * other statuses and a few new ones: only new orders with the deadline before the boundary, oldest first by the keyset
 * (placed_at, id), and the plan the planner picks on its own statistics (after ANALYZE) reads the index
 * ordering_order_status_placed_at_id_idx, never the whole table.
 *
 * The orders are in a temporary table that takes the place of ordering_order for the connection of the test (a copy of
 * its definition with its indexes, named like the ones of the table), so the table of the test database is not filled
 * with rows that the rollback leaves behind as garbage.
 */
final class DbalOverdueOrderIdsTest extends KernelTestCase
{
    private const int ORDERS = 100_000;
    /** The new orders are the last ones: n = 100 001 … 100 006, placed five minutes apart. */
    private const int NEW_ORDERS = 6;

    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE ordering_order (LIKE public.ordering_order INCLUDING ALL)');
        $this->nameTheIndexesLikeTheOnesOfTheTable();
        $this->fill();
        $this->connection->executeStatement('ANALYZE ordering_order');
    }

    /**
     * One test for the reads and the plan, so the 100 000 orders are written and analysed once.
     */
    public function testOnlyNewOrdersPastTheBoundaryInTheOrderOfTheKeysetThroughTheIndex(): void
    {
        $recording = RecordingConnection::like($this->connection);
        $ids = new DbalOverdueOrderIds($recording);
        // The deadlines of the new orders: n × 5 minutes + 30 minutes after 2026-01-01; the boundary leaves out the
        // last two (n = 100 005 at the boundary itself, n = 100 006 after it).
        $boundary = new \DateTimeImmutable('2026-01-01 00:30:00+00:00')->modify('+'.(100_005 * 5).' minutes');

        $first = $ids->page($boundary, null, 3);
        $plan = $recording->planOfTheLastQuery($this->connection, false);
        $second = $ids->page($boundary, $first[2], 3);
        $third = $ids->page($boundary, $second[0] ?? $first[2], 3);

        self::assertSame(['n100001', 'n100002', 'n100003'], $this->numbers($first));
        self::assertSame(['n100004'], $this->numbers($second));
        self::assertSame([], $third);
        self::assertEquals(new \DateTimeImmutable('2026-01-01 00:00:00+00:00')->modify('+'.(100_001 * 5).' minutes'), $first[0]->placedAt);
        self::assertStringContainsString('ordering_order_status_placed_at_id_idx', $plan, $plan);
        self::assertDoesNotMatchRegularExpression('/Seq Scan on ordering_order(?![_a-z])/', $plan, $plan);
    }

    public function testOrdersPlacedAtTheSameMomentAreTakenOneAfterAnotherByTheirIds(): void
    {
        $this->connection->executeStatement("UPDATE ordering_order SET placed_at = timestamptz '2026-01-01 00:00:00+00' WHERE status = 'new'");
        $ids = new DbalOverdueOrderIds($this->connection);
        $boundary = new \DateTimeImmutable('2030-01-01 00:00:00+00:00');

        $taken = [];
        $after = null;
        while ([] !== $page = $ids->page($boundary, $after, 4)) {
            $taken = [...$taken, ...array_map(static fn (OverdueOrder $order): string => $order->orderId, $page)];
            $after = $page[\count($page) - 1];
        }

        $expected = $this->connection->fetchFirstColumn("SELECT id FROM ordering_order WHERE status = 'new' ORDER BY id");
        self::assertSame($expected, $taken);
        self::assertCount(self::NEW_ORDERS, $taken);
    }

    /**
     * @param list<OverdueOrder> $page
     *
     * @return list<string> the numbers of the orders
     */
    private function numbers(array $page): array
    {
        return array_map(function (OverdueOrder $order): string {
            $number = $this->connection->fetchOne('SELECT number FROM ordering_order WHERE id = ?', [$order->orderId]);
            self::assertIsString($number);

            return $number;
        }, $page);
    }

    private function nameTheIndexesLikeTheOnesOfTheTable(): void
    {
        /** @var array<string, string> $renames */
        $renames = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT t.indexname, p.indexname
                FROM pg_indexes p
                JOIN pg_indexes t ON regexp_replace(t.indexdef, '^.* USING ', '') = regexp_replace(p.indexdef, '^.* USING ', '')
                WHERE p.schemaname = 'public' AND p.tablename = 'ordering_order'
                  AND t.schemaname = pg_my_temp_schema()::regnamespace::text AND t.tablename = 'ordering_order'
                  AND t.indexname <> p.indexname
                SQL,
        );
        foreach ($renames as $copy => $name) {
            $this->connection->executeStatement(\sprintf('ALTER INDEX pg_temp.%s RENAME TO %s', $copy, $name));
        }
    }

    /**
     * 100 000 orders paid, delivered or cancelled (all past their deadline), then NEW_ORDERS new ones numbered n100001….
     */
    private function fill(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ordering_order (
                    id, number, status, owner_type, customer_id, access_token, email, full_name, phone, street, postal_code, city, country, currency,
                    total_net, total_vat, total_gross, idempotency_key, request_fingerprint, placed_at, payment_deadline,
                    paid_at, payment_id, shipped_at, delivered_at, cancelled_at, cancellation_reason, version
                )
                SELECT gen_random_uuid(), CASE WHEN s.status = 'new' THEN 'n'||n ELSE 'R-'||n END, s.status, 'guest', NULL, substr(md5('t'||n)||md5('u'||n), 1, 43),
                       substr(md5('e'||n), 1, 12)||'@example.com', 'Jan Kowalski',
                       '+48600100200', 'Długa 1', '00-950', 'Warszawa', 'PL', 'PLN', 1000, 0, 1000,
                       'plan-'||n, repeat('a', 64), timestamptz '2026-01-01 00:00:00+00' + n * interval '5 minutes',
                       timestamptz '2026-01-01 00:30:00+00' + n * interval '5 minutes',
                       CASE WHEN s.status IN ('paid', 'delivered') THEN timestamptz '2026-01-01 00:05:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status IN ('paid', 'delivered') THEN gen_random_uuid() END,
                       CASE WHEN s.status = 'delivered' THEN timestamptz '2026-01-01 01:00:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status = 'delivered' THEN timestamptz '2026-01-01 02:00:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status = 'cancelled' THEN timestamptz '2026-01-01 00:35:00+00' + n * interval '5 minutes' END,
                       CASE WHEN s.status = 'cancelled' THEN 'payment_timeout' END,
                       1
                FROM generate_series(1, :orders) AS n
                CROSS JOIN LATERAL (SELECT CASE WHEN n > :others THEN 'new' WHEN n % 10 = 0 THEN 'cancelled' WHEN n % 50 = 1 THEN 'paid' ELSE 'delivered' END AS status) s
                SQL,
            ['orders' => self::ORDERS + self::NEW_ORDERS, 'others' => self::ORDERS],
        );
    }
}
