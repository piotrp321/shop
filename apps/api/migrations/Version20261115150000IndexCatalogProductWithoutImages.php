<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The partial index of the products without images, by id: the list of them in the panel and the queue of the dashboard
 * read only the few rows of the index, not the table of millions of products.
 *
 * Built CONCURRENTLY, so writes of products go on while a large table is indexed. That cannot run in a transaction, so
 * the migration is not transactional and every statement commits on its own. A build that failed (or whose Job was
 * stopped) leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so
 * a retried Job `migrate` builds it anew. A valid index is left as it is.
 */
final class Version20261115150000IndexCatalogProductWithoutImages extends AbstractMigration
{
    private const string INDEX = 'catalog_product_without_images_idx';

    public function getDescription(): string
    {
        return 'Partial index (id) of the products without images of catalog_product, built concurrently.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($this->isInvalid(self::INDEX)) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        }
        $this->addSql("CREATE INDEX CONCURRENTLY IF NOT EXISTS ".self::INDEX." ON catalog_product (id) WHERE images = '[]'::jsonb");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }

    private function isInvalid(string $name): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
            [$name],
        );
    }
}
