<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The indexes of the order list sorted by the amount: (total_gross, id) and (status, total_gross, id) for the list
 * of one status, the way the indexes of placed_at serve the sort by placement. Both directions of the sort read the
 * same index (forwards or backwards), because the id follows the direction of the amount.
 *
 * Built CONCURRENTLY, so orders can be placed while a large table is indexed. That cannot run in a transaction, so
 * the migration is not transactional and every statement commits on its own. A build that failed (or whose Job was
 * stopped) leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so
 * a retried Job `migrate` builds it anew. A valid index is left as it is.
 */
final class Version20261115130100IndexOrderTotals extends AbstractMigration
{
    private const array INDEXES = [
        'ordering_order_total_gross_id_idx' => '(total_gross, id)',
        'ordering_order_status_total_gross_id_idx' => '(status, total_gross, id)',
    ];

    public function getDescription(): string
    {
        return 'Indexes by total_gross of ordering_order, with and without the status, built concurrently.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::INDEXES as $name => $columns) {
            if ($this->isInvalid($name)) {
                $this->addSql("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
            $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON ordering_order {$columns}");
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
