<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The unique index of the SKU of a variant: no two variants of any products have the same code, a drafts included, and
 * any number of them have none (NULL is not equal to NULL in a unique index). It is the guarantee behind the check of the
 * handler: a write that lost a race with another one is refused here ({@see \App\Catalog\Domain\Product\SkuAlreadyTaken}).
 *
 * Built CONCURRENTLY, so writes of products go on while a large table is indexed. That cannot run in a transaction, so
 * the migration is not transactional and every statement commits on its own. A build that failed (or whose Job was
 * stopped) leaves an INVALID index of its name, which IF NOT EXISTS would keep for good: that one is dropped first, so
 * a retried Job `migrate` builds it anew. A valid index is left as it is.
 */
final class Version20261125110100IndexVariantSku extends AbstractMigration
{
    private const string INDEX = 'catalog_product_variant_sku_key';

    public function getDescription(): string
    {
        return 'Unique index of catalog_product_variant.sku, built concurrently.';
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
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON catalog_product_variant (sku)');
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
