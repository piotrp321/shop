<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103120000CreateCartTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the cart tables: carts of guests and customers and their lines.';
    }

    public function up(Schema $schema): void
    {
        // No foreign keys to the catalog tables: Cart is a separate module, even in the same database. The CHECK
        // constraints are the last line of defence of the rules the Cart aggregate keeps: a customer id exactly for
        // a customer cart, only a guest cart merged, 1 to 99 units in a line. One cart per customer (partial unique
        // index, also the target of INSERT … ON CONFLICT); expires_at is indexed for the purge of expired carts.
        $this->addSql("CREATE TABLE cart_cart (id UUID NOT NULL, owner_type VARCHAR(16) NOT NULL, customer_id VARCHAR(255) DEFAULT NULL, status VARCHAR(16) NOT NULL, merged_into UUID DEFAULT NULL, merged_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id), CONSTRAINT cart_cart_owner_check CHECK ((owner_type = 'customer') = (customer_id IS NOT NULL)), CONSTRAINT cart_cart_status_check CHECK (status = 'open' OR owner_type = 'guest'))");
        $this->addSql('CREATE UNIQUE INDEX cart_cart_customer_id_key ON cart_cart (customer_id) WHERE (customer_id IS NOT NULL)');
        $this->addSql('CREATE INDEX cart_cart_expires_at_idx ON cart_cart (expires_at)');
        // Lines go with their cart (ON DELETE CASCADE): carts are short-lived, unlike the reservations of Inventory.
        $this->addSql('CREATE TABLE cart_line (id UUID NOT NULL, cart_id UUID NOT NULL, product_id UUID NOT NULL, quantity INT NOT NULL, added_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id), CONSTRAINT cart_line_quantity_check CHECK (quantity BETWEEN 1 AND 99))');
        $this->addSql('CREATE UNIQUE INDEX cart_line_cart_product_key ON cart_line (cart_id, product_id)');
        $this->addSql('CREATE INDEX cart_line_cart_id_idx ON cart_line (cart_id)');
        $this->addSql('ALTER TABLE cart_line ADD CONSTRAINT cart_line_cart_fk FOREIGN KEY (cart_id) REFERENCES cart_cart (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // The lines first: they reference the cart table through the foreign key.
        $this->addSql('DROP TABLE cart_line');
        $this->addSql('DROP TABLE cart_cart');
    }
}
