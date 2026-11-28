<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops catalog_product_primary_category_id_idx (primary_category_id): it is a prefix of
 * catalog_product_category_name_id_idx (primary_category_id, name, id), which serves every read it served (equality on
 * the category, the foreign key check), so every write of a product only paid for one more entry.
 *
 * Dropped CONCURRENTLY, so the table is not locked against writes. That cannot run in a transaction, so the migration
 * is not transactional.
 */
final class Version20261128110000DropRedundantCatalogProductCategoryIndex extends AbstractMigration
{
    private const string INDEX = 'catalog_product_primary_category_id_idx';

    public function getDescription(): string
    {
        return 'Drops the index of catalog_product.primary_category_id, a prefix of catalog_product_category_name_id_idx.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON catalog_product (primary_category_id)');
    }
}
