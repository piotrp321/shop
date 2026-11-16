<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine;

use App\Inventory\Application\Port\StockRecords;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\VariantId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Plain statements on the connection of the entity manager (the exception of Inventory, see CLAUDE.md), one per
 * batch of products, in the transaction of command.bus.
 */
final readonly class DbalStockRecords implements StockRecords
{
    /**
     * The first key of the two-key advisory locks of the products (pg_advisory_xact_lock(int, int)), chosen for them;
     * the second is a hash of the product id. Two products with the same hash only wait for each other.
     */
    public const int LOCK_NAMESPACE = 0x5354; // "ST"

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Transaction-level advisory locks, in ascending order of their keys (the global order, also when two products
     * share a key), with one statement: unnest WITH ORDINALITY returns the keys in that order.
     */
    public function lockProducts(array $productIds): void
    {
        // Outside a transaction the lock would end with its own statement and guard nothing.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The locks of the products need the transaction of the sync.');
        }
        $keys = array_values(array_unique(array_map(self::lockKey(...), $productIds)));
        sort($keys);
        if ([] === $keys) {
            return;
        }

        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(CAST(:namespace AS int), k.key)
             FROM unnest(CAST(:keys AS int[])) WITH ORDINALITY AS k(key, position)
             ORDER BY k.position',
            ['namespace' => self::LOCK_NAMESPACE, 'keys' => '{'.implode(',', $keys).'}'],
        );
    }

    /**
     * One INSERT … ON CONFLICT DO UPDATE … WHERE product_id IS NULL: an existing record with its product is neither
     * updated nor counted, and its version and quantities never change. The rows go in ascending variant id, the order
     * in which lockMany() locks, so the row locks the conflicts take cannot deadlock with a reservation.
     */
    public function ensure(array $variantsByProduct): int
    {
        [$variants, $products] = self::pairs($variantsByProduct);
        if ([] === $variants) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (variant_id, product_id, physical, reserved, version, updated_at)
             SELECT v.variant_id, v.product_id, 0, 0, 0, :now
             FROM unnest(CAST(:variants AS uuid[]), CAST(:products AS uuid[])) AS v(variant_id, product_id)
             ORDER BY v.variant_id
             ON CONFLICT (variant_id) DO UPDATE SET product_id = excluded.product_id
             WHERE inventory_stock_item.product_id IS NULL',
            ['variants' => self::array($variants), 'products' => self::array($products), 'now' => new \DateTimeImmutable()],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * `physical - reserved = 0` is the predicate of the out-of-stock index by product, so the records of the products
     * are found through it; physical = reserved = 0 is the condition. Its row locks do not follow the order of
     * lockMany(): a reservation of the removed variant at the same moment may deadlock with it, which PostgreSQL
     * detects by failing one of the two transactions (the event is retried, the backfill run again).
     */
    public function removeEmptyOfRemovedVariants(array $variantsByProduct): int
    {
        [$variants] = self::pairs($variantsByProduct);

        return (int) $this->connection->executeStatement(
            'DELETE FROM inventory_stock_item s
             USING unnest(CAST(:products AS uuid[])) AS p(id)
             WHERE s.product_id = p.id
               AND s.physical - s.reserved = 0
               AND s.physical = 0 AND s.reserved = 0
               AND s.variant_id <> ALL(CAST(:variants AS uuid[]))',
            ['products' => self::array(array_map(strval(...), array_keys($variantsByProduct))), 'variants' => self::array($variants)],
        );
    }

    /**
     * The CRC-32 of the id as a signed 32-bit integer (the int of PostgreSQL).
     */
    private static function lockKey(ProductId $productId): int
    {
        $hash = crc32($productId->toString());

        return $hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash;
    }

    /**
     * @param array<string, list<VariantId>> $variantsByProduct
     *
     * @return array{list<string>, list<string>} the variant ids and, at the same positions, their product ids
     */
    private static function pairs(array $variantsByProduct): array
    {
        $variants = [];
        $products = [];
        foreach ($variantsByProduct as $productId => $variantIds) {
            foreach ($variantIds as $variantId) {
                $variants[] = $variantId->toString();
                $products[] = (string) $productId;
            }
        }

        return [$variants, $products];
    }

    /**
     * A PostgreSQL array literal of validated UUIDs (hex digits and dashes only), bound as one parameter.
     *
     * @param list<string> $ids
     */
    private static function array(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
