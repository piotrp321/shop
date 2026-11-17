<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The partial indexes of the stock levels, by product: the records with nothing available and those of the low stock
 * level. The bound 5 is AvailabilityLevel::LOW_STOCK_THRESHOLD; InventorySchemaTest fails when the two differ.
 *
 * Built CONCURRENTLY, so writes of stock go on while a large table is indexed. That cannot run in a transaction, so
 * the migration is not transactional and every statement commits on its own. A build that failed (or whose Job was
 * stopped) leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so
 * a retried Job `migrate` builds it anew. A valid index is left as it is.
 */
final class Version20261115120100IndexStockLevels extends AbstractMigration
{
    private const array INDEXES = [
        'inventory_stock_item_out_of_stock_idx' => '(physical - reserved) = 0',
        'inventory_stock_item_low_stock_idx' => '(physical - reserved) BETWEEN 1 AND 5',
    ];

    public function getDescription(): string
    {
        return 'Partial indexes by product of the out-of-stock and low-stock records of inventory_stock_item, built concurrently.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $name => $predicate) {
            if ($this->isInvalid($name)) {
                $this->addSql("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
            $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON inventory_stock_item (product_id) WHERE {$predicate}");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            $this->addSql("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    private function isInvalid(string $name): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
            [$name],
        );
    }
}
