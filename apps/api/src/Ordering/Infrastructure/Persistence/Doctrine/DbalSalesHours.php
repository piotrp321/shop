<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\SalesHours;
use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Domain\OrderId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * ordering_sales_hour through DBAL, not through records of the ORM (SalesHourRecord only defines the schema): an
 * addition is one upsert that joins the transaction of the caller, so concurrent payments of one hour and shard wait
 * for each other's row instead of failing on its key. The hour and the shard are computed in SQL by the expressions
 * the rebuild groups the orders by (SalesSql), so both writes put an order in the same row.
 */
final readonly class DbalSalesHours implements SalesHours
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(OrderId $orderId, \DateTimeImmutable $paidAt, int $gross): void
    {
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO ordering_sales_hour AS s (hour_start, shard, order_count, gross)
                 VALUES (%s, %s, 1, :gross)
                 ON CONFLICT (hour_start, shard) DO UPDATE SET order_count = s.order_count + 1, gross = s.gross + EXCLUDED.gross',
                SalesSql::hourOf('CAST(:paid_at AS timestamptz)'),
                SalesSql::shardOf('CAST(:order AS uuid)'),
            ),
            ['paid_at' => $paidAt, 'order' => $orderId->toString(), 'gross' => $gross],
            ['paid_at' => Types::DATETIMETZ_IMMUTABLE, 'gross' => Types::BIGINT],
        );
    }

    public function totals(\DateTimeImmutable $from, \DateTimeImmutable $to): SalesTotals
    {
        $row = $this->connection->fetchNumeric(
            'SELECT CAST(COALESCE(SUM(order_count), 0) AS bigint), CAST(COALESCE(SUM(gross), 0) AS bigint)
             FROM ordering_sales_hour WHERE hour_start >= :from AND hour_start < :to',
            ['from' => $from, 'to' => $to],
            ['from' => Types::DATETIMETZ_IMMUTABLE, 'to' => Types::DATETIMETZ_IMMUTABLE],
        );
        \assert(\is_array($row) && \is_int($row[0]) && \is_int($row[1]));

        return new SalesTotals($row[0], $row[1]);
    }
}
