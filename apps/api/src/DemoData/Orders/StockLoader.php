<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

use App\DemoData\Copy\CopyWriter;
use App\DemoData\Copy\Sql;
use App\DemoData\Profile\RunContext;
use Doctrine\DBAL\Connection;

/**
 * Steps 6 and 7 of the generator (spec: "Stany po zamówieniach", P4): the stock, the reservations and the stock
 * movements as one pass of SQL over the loaded orders, and the number sequence past the last number.
 *
 * Only the orders of the 90 days before the anchor leave reservations and movements (the older ones are as if archived):
 * a paid order an active reservation (key = the id of the order, its lines), a shipped one (also delivered or returned)
 * a committed one, a cancelled one a released one. A variant with such orders starts the window with the opening
 * balance (a receipt of the source `seed`, reference `opening:<variantId>`), then the reservation at the placement, the
 * commit at the shipment or the release at the cancellation (source `system`, reference = the key of the reservation),
 * with the values after each from running sums in the order of the moments and ids. Its physical stock is a quantity
 * from a hash of the seed and the variant, but never below the peak of its reservations active at one time in the
 * window, so no movement breaks physical >= reserved; the opening balance is that stock plus what the window
 * committed, and the last movement is the stock. A variant without orders in the window gets the quantity of the hash
 * as its opening receipt, or, for about 8% of them, an empty record without movements (like CreateEmptyStock). Every
 * variant without a stock record gets one, with its product.
 *
 * Ids of movements are UUID v7 of their moment with bits from md5 of the seed, the kind, the reference and the variant.
 *
 * Tables written: inventory_reservation, inventory_reservation_line, inventory_stock_movement, inventory_stock_item.
 */
final readonly class StockLoader
{
    public const int WINDOW_DAYS = 90;
    /** Of every 100 variants without orders in the window, this many have no stock. */
    public const int EMPTY_PERCENT = 8;

    public function __construct(
        private Connection $connection,
        private CopyWriter $copy,
    ) {
    }

    /**
     * @return array{reservations: int, movements: int, items: int}
     */
    public function load(RunContext $context): array
    {
        $parameters = [
            'start' => $context->anchor->modify(\sprintf('-%d days', self::WINDOW_DAYS))->format('Y-m-d H:i:sP'),
            'seed' => $context->namespace,
        ];

        return $this->connection->transactional(function () use ($parameters): array {
            $this->copy->relaxDurability();
            $this->connection->executeStatement('DROP TABLE IF EXISTS demo_stock_event, demo_stock_variant');

            $reservations = (int) $this->connection->executeStatement(
                "INSERT INTO inventory_reservation (reservation_key, status, created_at, updated_at)
                 SELECT id::text, CASE status WHEN 'paid' THEN 'active' WHEN 'cancelled' THEN 'released' ELSE 'committed' END, placed_at,
                        CASE status WHEN 'paid' THEN placed_at WHEN 'cancelled' THEN cancelled_at ELSE shipped_at END
                 FROM ordering_order WHERE placed_at >= CAST(:start AS timestamptz)",
                $parameters,
            );
            $this->connection->executeStatement(
                'INSERT INTO inventory_reservation_line (reservation_key, variant_id, quantity)
                 SELECT o.id::text, l.variant_id, l.quantity FROM ordering_order o JOIN ordering_order_line l ON l.order_id = o.id
                 WHERE o.placed_at >= CAST(:start AS timestamptz)',
                $parameters,
            );

            // The movements of the reservations, with their ids, then the running sums by variant.
            $this->connection->executeStatement(
                'CREATE TEMP TABLE demo_stock_event AS
                 SELECT e.*, '.self::uuidV7('e.occurred_at', "md5(:seed || ':' || e.type || ':' || e.reference || ':' || e.variant_id::text)")." AS id
                 FROM (
                     SELECT l.variant_id, o.placed_at AS occurred_at, 'reservation' AS type, 0 AS physical_delta, l.quantity AS reserved_delta, o.id::text AS reference
                     FROM ordering_order o JOIN ordering_order_line l ON l.order_id = o.id WHERE o.placed_at >= CAST(:start AS timestamptz)
                     UNION ALL
                     SELECT l.variant_id, o.shipped_at, 'commit', -l.quantity, -l.quantity, o.id::text
                     FROM ordering_order o JOIN ordering_order_line l ON l.order_id = o.id WHERE o.placed_at >= CAST(:start AS timestamptz) AND o.shipped_at IS NOT NULL
                     UNION ALL
                     SELECT l.variant_id, o.cancelled_at, 'release', 0, -l.quantity, o.id::text
                     FROM ordering_order o JOIN ordering_order_line l ON l.order_id = o.id WHERE o.placed_at >= CAST(:start AS timestamptz) AND o.cancelled_at IS NOT NULL
                 ) e",
                $parameters,
            );
            $this->connection->executeStatement(
                'CREATE TEMP TABLE demo_stock_variant AS
                 SELECT v.id AS variant_id, v.product_id, coalesce(t.peak, 0) AS peak, coalesce(t.committed, 0) AS committed,
                        coalesce(t.reserved, 0) AS reserved, coalesce(t.movements, 0) AS movements, t.last_at,
                        CASE WHEN h.hash % 100 < '.self::EMPTY_PERCENT.' THEN 0
                             ELSE 1 + (h.hash / 100) % 40 + CASE WHEN (h.hash / 4000) % 5 = 0 THEN (h.hash / 20000) % 300 ELSE 0 END END AS drawn
                 FROM catalog_product_variant v
                 CROSS JOIN LATERAL (SELECT CAST(CAST(\'x\' || substr(md5(:seed || \':stock:\' || v.id::text), 1, 7) AS bit(28)) AS int) AS hash) h
                 LEFT JOIN (
                     SELECT variant_id, max(reserved_running) AS peak, -sum(physical_delta) AS committed, sum(reserved_delta) AS reserved, count(*) AS movements, max(occurred_at) AS last_at
                     FROM (SELECT variant_id, occurred_at, physical_delta, reserved_delta, sum(reserved_delta) OVER (PARTITION BY variant_id ORDER BY occurred_at, id ROWS UNBOUNDED PRECEDING) AS reserved_running FROM demo_stock_event) r
                     GROUP BY variant_id
                 ) t ON t.variant_id = v.id
                 WHERE NOT EXISTS (SELECT 1 FROM inventory_stock_item s WHERE s.variant_id = v.id)',
                $parameters,
            );

            // The opening receipt: the stock at the end and what the window committed.
            $openings = (int) $this->connection->executeStatement(
                'INSERT INTO inventory_stock_movement (id, variant_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reason, note, reference, source, actor_id, actor_name, occurred_at)
                 SELECT '.self::uuidV7('CAST(:start AS timestamptz)', "md5(:seed || ':receipt:opening:' || variant_id::text)").", variant_id, 'receipt',
                        greatest(peak, drawn) + committed, 0, greatest(peak, drawn) + committed, 0, NULL, NULL, 'opening:' || variant_id::text, 'seed', NULL, NULL, CAST(:start AS timestamptz)
                 FROM demo_stock_variant WHERE movements > 0 OR drawn > 0",
                $parameters,
            );
            $movements = (int) $this->connection->executeStatement(
                "INSERT INTO inventory_stock_movement (id, variant_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reason, note, reference, source, actor_id, actor_name, occurred_at)
                 SELECT e.id, e.variant_id, e.type, e.physical_delta, e.reserved_delta,
                        greatest(v.peak, v.drawn) + v.committed + sum(e.physical_delta) OVER w, sum(e.reserved_delta) OVER w,
                        NULL, NULL, e.reference, 'system', NULL, NULL, e.occurred_at
                 FROM demo_stock_event e JOIN demo_stock_variant v ON v.variant_id = e.variant_id
                 WINDOW w AS (PARTITION BY e.variant_id ORDER BY e.occurred_at, e.id ROWS UNBOUNDED PRECEDING)",
                $parameters,
            );
            $items = (int) $this->connection->executeStatement(
                'INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at)
                 SELECT variant_id, product_id, CASE WHEN movements > 0 OR drawn > 0 THEN greatest(peak, drawn) ELSE 0 END, reserved,
                        CASE WHEN movements > 0 OR drawn > 0 THEN movements + 1 ELSE 0 END, coalesce(last_at, CAST(:start AS timestamptz))
                 FROM demo_stock_variant',
                $parameters,
            );
            $this->connection->executeStatement('DROP TABLE demo_stock_event, demo_stock_variant');

            return ['reservations' => $reservations, 'movements' => $openings + $movements, 'items' => $items];
        });
    }

    /**
     * Step 7: the sequence of the numbers past the last number of an order (never back, so a number is never given twice).
     *
     * @return int the last value of the sequence
     */
    public function advanceOrderNumbers(): int
    {
        return Sql::int($this->connection->fetchOne(
            "SELECT setval('ordering_order_number_seq', greatest((SELECT last_value FROM ordering_order_number_seq), coalesce((SELECT max(CAST(substr(number, 3) AS bigint)) FROM ordering_order), 0)), true)",
        ));
    }

    /**
     * A UUID v7 in SQL: the milliseconds of the moment and 74 bits of the 32 hexadecimal digits of the hash.
     */
    private static function uuidV7(string $moment, string $hash): string
    {
        return \sprintf(
            "CAST(lpad(to_hex(CAST(floor(extract(epoch FROM %1\$s) * 1000) AS bigint)), 12, '0') || '7' || substr(%2\$s, 1, 3) || substr('89ab', get_byte(decode(substr(%2\$s, 4, 2), 'hex'), 0) %% 4 + 1, 1) || substr(%2\$s, 6, 3) || substr(%2\$s, 9, 12) AS uuid)",
            $moment,
            $hash,
        );
    }
}
