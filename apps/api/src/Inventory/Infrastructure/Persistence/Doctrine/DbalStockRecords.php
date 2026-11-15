<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine;

use App\Inventory\Application\Port\StockRecords;
use App\Inventory\Domain\Stock\VariantId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Plain statements on the connection of the entity manager (the exception of Inventory, see CLAUDE.md), one per
 * batch of products, in the transaction of command.bus.
 */
final readonly class DbalStockRecords implements StockRecords
{
    public function __construct(private Connection $connection)
    {
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
     * are found through it; physical = reserved = 0 is the condition.
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
