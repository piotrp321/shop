<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment\Persistence;

use App\Payment\Application\Port\PendingPayment;
use App\Payment\Domain\OrderId;
use App\Payment\Infrastructure\Persistence\Doctrine\DbalPendingPayments;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pending payments payment:close-abandoned walks (spec: docs/features/async-reliability/spec.md, W6) on 100 000
 * payments that ended and a few pending ones: only the pending ones, by the keyset of the order id, and the plan the
 * planner picks on its own statistics (after ANALYZE) reads the partial index payment_payment_one_pending_per_order,
 * never the whole table.
 *
 * The payments are in a temporary table that takes the place of payment_payment for the connection of the test (a copy
 * of its definition with its indexes, named like the ones of the table), so the table of the test database is not
 * filled with rows that the rollback leaves behind as garbage.
 */
final class DbalPendingPaymentsTest extends KernelTestCase
{
    private const int ENDED = 100_000;
    private const int PENDING = 5;

    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE payment_payment (LIKE public.payment_payment INCLUDING ALL)');
        $this->nameTheIndexesLikeTheOnesOfTheTable();
        $this->fill();
        $this->connection->executeStatement('ANALYZE payment_payment');
    }

    public function testOnlyThePendingPaymentsByTheKeysetOfTheOrderThroughThePartialIndex(): void
    {
        $recording = RecordingConnection::like($this->connection);
        $pending = new DbalPendingPayments($recording);
        /** @var list<array{string, string}> $expected */
        $expected = $this->connection->fetchAllNumeric("SELECT order_id, id FROM payment_payment WHERE status = 'pending' ORDER BY order_id");

        $first = $pending->page(null, 3);
        $plan = $recording->planOfTheLastQuery($this->connection, false);
        $second = $pending->page($first[2]->orderId, 3);
        $third = $pending->page($second[1]->orderId, 3);

        self::assertSame($expected, array_map(static fn (PendingPayment $p): array => [$p->orderId->toString(), $p->paymentId->toString()], [...$first, ...$second]));
        self::assertCount(self::PENDING, [...$first, ...$second]);
        self::assertSame([], $third);
        self::assertStringContainsString('payment_payment_one_pending_per_order', $plan, $plan);
        self::assertDoesNotMatchRegularExpression('/Seq Scan on payment_payment(?![_a-z])/', $plan, $plan);
    }

    public function testAnOrderIdAfterTheLastIsAnEmptyPage(): void
    {
        self::assertSame([], new DbalPendingPayments($this->connection)->page(OrderId::fromString('ffffffff-ffff-7fff-bfff-ffffffffffff'), 100));
    }

    private function nameTheIndexesLikeTheOnesOfTheTable(): void
    {
        /** @var array<string, string> $renames */
        $renames = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT t.indexname, p.indexname
                FROM pg_indexes p
                JOIN pg_indexes t ON regexp_replace(t.indexdef, '^.* USING ', '') = regexp_replace(p.indexdef, '^.* USING ', '')
                WHERE p.schemaname = 'public' AND p.tablename = 'payment_payment'
                  AND t.schemaname = pg_my_temp_schema()::regnamespace::text AND t.tablename = 'payment_payment'
                  AND t.indexname <> p.indexname
                SQL,
        );
        foreach ($renames as $copy => $name) {
            $this->connection->executeStatement(\sprintf('ALTER INDEX pg_temp.%s RENAME TO %s', $copy, $name));
        }
    }

    /**
     * ENDED payments succeeded, failed or cancelled, and PENDING pending ones, each of its own order.
     */
    private function fill(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO payment_payment (id, order_id, provider, amount, currency, status, provider_payment_id, redirect_url, idempotency_key,
                                             request_fingerprint, created_at, completed_at, executed_at)
                SELECT gen_random_uuid(), gen_random_uuid(), 'demo-transfer', 1230, 'PLN', s.status, 'tx_'||n, 'http://localhost/pay/'||n, 'plan-'||n,
                       repeat('a', 64), timestamptz '2026-01-01 00:00:00+00' + n * interval '1 minute',
                       CASE WHEN s.status = 'pending' THEN NULL ELSE timestamptz '2026-01-01 00:10:00+00' + n * interval '1 minute' END,
                       CASE WHEN s.status = 'succeeded' THEN timestamptz '2026-01-01 00:05:00+00' + n * interval '1 minute' END
                FROM generate_series(1, :payments) AS n
                CROSS JOIN LATERAL (SELECT CASE WHEN n > :ended THEN 'pending' WHEN n % 10 = 0 THEN 'failed' WHEN n % 10 = 1 THEN 'cancelled' ELSE 'succeeded' END AS status) s
                SQL,
            ['payments' => self::ENDED + self::PENDING, 'ended' => self::ENDED],
        );
    }
}
