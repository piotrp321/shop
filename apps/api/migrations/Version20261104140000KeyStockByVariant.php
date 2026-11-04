<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104140000KeyStockByVariant extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key stocks, reservation lines and the stock history by product variant: product_id becomes variant_id; every value and id stays.';
    }

    public function up(Schema $schema): void
    {
        // The Catalog migration before this one gave every product a default variant with the id of the product, so
        // the stored product ids are the ids of those default variants: only names change. The primary keys and the
        // unique index of receipt keys keep their names (they are named after their tables) and follow the columns.
        $this->addSql('ALTER TABLE inventory_stock_item RENAME COLUMN product_id TO variant_id');
        $this->addSql('ALTER TABLE inventory_reservation_line RENAME COLUMN product_id TO variant_id');
        $this->addSql('ALTER TABLE inventory_stock_movement RENAME COLUMN product_id TO variant_id');
        $this->addSql('ALTER INDEX inventory_stock_movement_product_occurred_at_idx RENAME TO inventory_stock_movement_variant_occurred_at_idx');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX inventory_stock_movement_variant_occurred_at_idx RENAME TO inventory_stock_movement_product_occurred_at_idx');
        $this->addSql('ALTER TABLE inventory_stock_movement RENAME COLUMN variant_id TO product_id');
        $this->addSql('ALTER TABLE inventory_reservation_line RENAME COLUMN variant_id TO product_id');
        $this->addSql('ALTER TABLE inventory_stock_item RENAME COLUMN variant_id TO product_id');
    }
}
