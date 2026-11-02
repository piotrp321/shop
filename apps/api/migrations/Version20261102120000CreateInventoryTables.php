<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000CreateInventoryTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the inventory tables: the stock of a product, reservations with their lines and the append-only stock history.';
    }

    public function up(Schema $schema): void
    {
        // No foreign keys to the catalog tables: Inventory is a separate module, even in the same database.
        // The CHECK is the last line of defence of the invariant the StockItem aggregate keeps.
        $this->addSql('CREATE TABLE inventory_stock_item (product_id UUID NOT NULL, physical INT NOT NULL, reserved INT NOT NULL, version INT NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (product_id), CONSTRAINT inventory_stock_item_quantities_check CHECK (reserved >= 0 AND physical >= reserved AND physical <= 1000000))');
        $this->addSql('CREATE TABLE inventory_reservation (reservation_key VARCHAR(100) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (reservation_key))');
        $this->addSql('CREATE TABLE inventory_reservation_line (reservation_key VARCHAR(100) NOT NULL, product_id UUID NOT NULL, quantity INT NOT NULL, PRIMARY KEY (reservation_key, product_id))');
        $this->addSql('CREATE TABLE inventory_stock_movement (id UUID NOT NULL, product_id UUID NOT NULL, type VARCHAR(16) NOT NULL, physical_delta INT NOT NULL, reserved_delta INT NOT NULL, physical_after INT NOT NULL, reserved_after INT NOT NULL, reason VARCHAR(32) DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, reference VARCHAR(100) DEFAULT NULL, source VARCHAR(16) NOT NULL, actor_id VARCHAR(255) DEFAULT NULL, actor_name VARCHAR(255) DEFAULT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX inventory_stock_movement_product_occurred_at_idx ON inventory_stock_movement (product_id, occurred_at DESC, id)');
        // A receipt key belongs to one receipt, whatever the product.
        $this->addSql('CREATE UNIQUE INDEX inventory_stock_movement_receipt_reference_key ON inventory_stock_movement (reference) WHERE (type = \'receipt\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inventory_stock_movement');
        $this->addSql('DROP TABLE inventory_reservation_line');
        $this->addSql('DROP TABLE inventory_reservation');
        $this->addSql('DROP TABLE inventory_stock_item');
    }
}
