<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Application\Port\SalesHours;
use App\Ordering\Application\Port\SalesHoursRebuild;
use App\Ordering\Application\Port\SalesTotals;
use App\Ordering\Domain\OrderId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

/**
 * ordering_sales_hour through DBAL, not through records of the ORM (SalesHourRecord only defines the schema): an
 * addition is one upsert that joins the transaction of the caller, so concurrent payments of one hour and shard wait
 * for each other's row instead of failing on its key. The hour and the shard are computed in SQL by the expressions
 * the rebuild groups the orders by (SalesSql), so both writes put an order in the same row. The gross is that of the
 * goods after the discounts, without the delivery (docs/features/delivery/spec.md, P14): the payment adds
 * OrderTotals::goodsGross(), the rebuild sums total_gross - delivery_gross.
 *
 * The rebuild of an hour runs in a transaction of its own (READ COMMITTED, the default): it creates the 16 rows of the
 * hour when missing and locks them, and only then counts the orders paid in the hour, each statement on what has
 * committed by its start. A payment of the hour adds to one of these rows in its own transaction before it commits, so
 * it either committed before the lock was granted (the count sees its order and the rewrite replaces its addition) or
 * waits for the rebuild to commit and adds itself to the rewritten row; no order is lost or counted twice. The rows of
 * shards without orders are deleted before the commit, so the table holds what the payments alone would have written
 * (a payment waiting for a deleted row inserts it anew).
 */
final readonly class DbalSalesHours implements SalesHours, SalesHoursRebuild
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

    public function nextHour(?\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        // Each side is one step on its index (the partial index by paid_at, the primary key); LEAST ignores a NULL.
        $next = $this->connection->fetchOne(
            \sprintf(
                'SELECT LEAST(
                    (SELECT %s FROM ordering_order WHERE paid_at >= CAST(:from AS timestamptz) ORDER BY paid_at LIMIT 1),
                    (SELECT hour_start FROM ordering_sales_hour WHERE hour_start >= CAST(:from AS timestamptz) ORDER BY hour_start LIMIT 1)
                 )',
                SalesSql::hourOf('paid_at'),
            ),
            ['from' => null === $from ? '-infinity' : $from->format('Y-m-d H:i:sP')],
        );
        if (null === $next || false === $next) {
            return null;
        }
        $hour = Type::getType(Types::DATETIMETZ_IMMUTABLE)->convertToPHPValue($next, $this->connection->getDatabasePlatform());
        \assert($hour instanceof \DateTimeImmutable);

        return $hour;
    }

    public function rebuild(\DateTimeImmutable $hour): SalesTotals
    {
        $parameters = ['hour' => $hour, 'next' => $hour->modify('+1 hour')];
        $types = ['hour' => Types::DATETIMETZ_IMMUTABLE, 'next' => Types::DATETIMETZ_IMMUTABLE];

        return $this->connection->transactional(function (Connection $connection) use ($parameters, $types): SalesTotals {
            $connection->executeStatement(
                'INSERT INTO ordering_sales_hour (hour_start, shard, order_count, gross)
                 SELECT :hour, shard, 0, 0 FROM generate_series(0, 15) AS shard
                 ON CONFLICT (hour_start, shard) DO NOTHING',
                $parameters,
                $types,
            );
            $connection->executeQuery('SELECT shard FROM ordering_sales_hour WHERE hour_start = :hour ORDER BY shard FOR UPDATE', $parameters, $types);
            // Counted only now, under the lock: what committed before it is counted, what did not waits for it.
            $connection->executeStatement(
                \sprintf(
                    'UPDATE ordering_sales_hour AS h SET order_count = COALESCE(o.order_count, 0), gross = COALESCE(o.gross, 0)
                     FROM generate_series(0, 15) AS s(shard)
                     LEFT JOIN (
                         SELECT %s AS shard, COUNT(*) AS order_count, SUM(total_gross - delivery_gross) AS gross
                         FROM ordering_order WHERE paid_at >= :hour AND paid_at < :next GROUP BY 1
                     ) AS o ON o.shard = s.shard
                     WHERE h.hour_start = :hour AND h.shard = s.shard',
                    SalesSql::shardOf('id'),
                ),
                $parameters,
                $types,
            );
            $connection->executeStatement('DELETE FROM ordering_sales_hour WHERE hour_start = :hour AND order_count = 0', $parameters, $types);

            return $this->totals($parameters['hour'], $parameters['next']);
        });
    }
}
