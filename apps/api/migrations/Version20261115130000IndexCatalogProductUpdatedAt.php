<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The indexes of the product list sorted by the moment of the last change: (updated_at, id) and its partial variant of
 * the drafts, the way (name, id) and the draft (name, id) serve the sort by name. Both directions of the sort read the
 * same index (forwards or backwards), because the id follows the direction of the column.
 *
 * Built CONCURRENTLY, so writes of products go on while a large table is indexed. That cannot run in a transaction, so
 * the migration is not transactional and every statement commits on its own. A build that failed (or whose Job was
 * stopped) leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so
 * a retried Job `migrate` builds it anew. A valid index is left as it is.
 */
final class Version20261115130000IndexCatalogProductUpdatedAt extends AbstractMigration
{
    private const array INDEXES = [
        'catalog_product_updated_at_id_idx' => '',
        'catalog_product_draft_updated_at_id_idx' => " WHERE status = 'draft'",
    ];

    public function getDescription(): string
    {
        return 'Indexes (updated_at, id) of catalog_product, whole and of the drafts, built concurrently.';
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
            $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON catalog_product (updated_at, id){$predicate}");
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
