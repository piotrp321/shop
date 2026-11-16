<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Query\BackofficeOrderSort;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalBackofficeOrderReader;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The first page of every sort of the order list, planned on 20 000 orders in which a status is a small share (paid:
 * 2 %), as in a shop, not on the few rows of a unit test: the plan depends on the statistics of the table, and a table
 * of seven orders, most of them paid, makes the planner prefer the index without the status and filter by it.
 *
 * The orders are in a temporary table that takes the place of ordering_order for the connection of the test (a copy of
 * its definition with its indexes, analysed), so the statistics of the table of the test database do not matter and it
 * is not filled with rows that the rollback leaves behind as garbage.
 */
final class DbalBackofficeOrderReaderPlanTest extends KernelTestCase
{
    private const int ORDERS = 20_000;

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
     * The copy names its indexes after its columns, so an index with a name of its own (the partial one of the pending
     * refunds) gets another one: every index of the copy takes the name of the index of the table with the same definition.
     */
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
     * @return iterable<string, array{BackofficeOrderSort, ?OrderStatus, string}>
     */
    public static function indexedFirstPages(): iterable
    {
        yield 'newest first' => [BackofficeOrderSort::PlacedDescending, null, 'ordering_order_placed_at_id_idx'];
        yield 'oldest first' => [BackofficeOrderSort::PlacedAscending, null, 'ordering_order_placed_at_id_idx'];
        yield 'highest amount first' => [BackofficeOrderSort::TotalDescending, null, 'ordering_order_total_gross_id_idx'];
        yield 'lowest amount first' => [BackofficeOrderSort::TotalAscending, null, 'ordering_order_total_gross_id_idx'];
        yield 'newest first of a status' => [BackofficeOrderSort::PlacedDescending, OrderStatus::Paid, 'ordering_order_status_placed_at_id_idx'];
        yield 'oldest first of a status' => [BackofficeOrderSort::PlacedAscending, OrderStatus::Paid, 'ordering_order_status_placed_at_id_idx'];
        yield 'highest amount first of a status' => [BackofficeOrderSort::TotalDescending, OrderStatus::Paid, 'ordering_order_status_total_gross_id_idx'];
        yield 'lowest amount first of a status' => [BackofficeOrderSort::TotalAscending, OrderStatus::Paid, 'ordering_order_status_total_gross_id_idx'];
        yield 'the pending refunds' => [BackofficeOrderSort::RefundDueDate, null, 'ordering_order_pending_refund_idx'];
    }

    #[DataProvider('indexedFirstPages')]
    public function testTheFirstPageOfEverySortIsServedByItsIndexWithoutASort(BackofficeOrderSort $sort, ?OrderStatus $status, string $index): void
    {
        $recording = RecordingConnection::like($this->connection);
        $reader = new DbalBackofficeOrderReader($recording, new BoundedCounter($recording));

        $reader->page(new BackofficeOrderFilter($status, BackofficeOrderSort::RefundDueDate === $sort, null, null, $sort), null, 26);
        $plan = $recording->planOfTheLastQuery($this->connection);

        self::assertStringContainsString($index, $plan);
        self::assertStringNotContainsString('Sort', $plan);
        self::assertStringNotContainsString('Seq Scan', $plan);
    }

    /**
     * Paid and new orders in fifties (2 % each, as in a shop the orders leave these statuses quickly), the rest delivered,
     * and a returned order with a refund for every thousandth: every other of them is completed.
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
