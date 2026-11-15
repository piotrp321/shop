<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine;

use App\Inventory\Application\Port\StockLevelProducts;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\ProductId;
use App\Shared\Domain\BoundedCount;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Plain statements on the stock records, without a lock. The condition of a level repeats the predicate of its partial
 * index by product (Version20261115120100IndexStockLevels) as a literal, never as a parameter: only then can the
 * planner prove the predicate in a generic plan. The index delivers the products in ascending order, so a page is a
 * grouped read of its first rows and the count stops at its limit. A record without its product (not yet synced) is
 * left out: in the index its NULL product comes last, behind every product.
 */
final readonly class DbalStockLevelProducts implements StockLevelProducts
{
    public function __construct(private Connection $connection)
    {
    }

    public function page(AvailabilityLevel $level, ?ProductId $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The page limit must be at least 1.');
        }
        $condition = self::condition($level);
        // Two statements rather than "IS NULL OR", so the cursor stays an index condition.
        $ids = null === $after
            ? $this->connection->fetchFirstColumn(
                "SELECT product_id FROM inventory_stock_item WHERE {$condition} GROUP BY product_id ORDER BY product_id LIMIT :limit",
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            )
            : $this->connection->fetchFirstColumn(
                "SELECT product_id FROM inventory_stock_item WHERE {$condition} AND product_id > CAST(:after AS uuid) GROUP BY product_id ORDER BY product_id LIMIT :limit",
                ['after' => $after->toString(), 'limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );

        return array_map(static function (mixed $id): ProductId {
            \assert(\is_string($id));

            return ProductId::fromString($id);
        }, $ids);
    }

    public function count(AvailabilityLevel $level): BoundedCount
    {
        $limit = BoundedCounter::DEFAULT_LIMIT;
        // The limit is an int, so it goes into the statement as a literal: the planner sees the row goal.
        $rows = $this->connection->fetchOne(\sprintf(
            'SELECT count(*) FROM (SELECT 1 FROM inventory_stock_item WHERE %s GROUP BY product_id LIMIT %d) bounded',
            self::condition($level),
            $limit + 1,
        ));
        \assert(\is_int($rows));

        return BoundedCount::ofRowsRead($rows, $limit);
    }

    /**
     * A constant fragment of the code: the bound of the low level is the constant of AvailabilityLevel.
     */
    private static function condition(AvailabilityLevel $level): string
    {
        return match ($level) {
            AvailabilityLevel::OutOfStock => 'physical - reserved = 0 AND product_id IS NOT NULL',
            AvailabilityLevel::LowStock => 'physical - reserved BETWEEN 1 AND '.AvailabilityLevel::LOW_STOCK_THRESHOLD.' AND product_id IS NOT NULL',
            AvailabilityLevel::InStock => throw new \InvalidArgumentException('The in-stock level is no filter of the stock list.'),
        };
    }
}
