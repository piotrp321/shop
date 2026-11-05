<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105130000CreateOrderingTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the ordering tables: orders, their lines and the sequence of the order numbers.';
    }

    public function up(Schema $schema): void
    {
        // R-100001 is the first number. A sequence is outside transactions: a rolled back placement leaves a gap.
        $this->addSql('CREATE SEQUENCE ordering_order_number_seq INCREMENT BY 1 MINVALUE 100001 START 100001');
        // No foreign keys to the tables of Cart, Catalog, Pricing or Inventory: an order keeps copies. The CHECK
        // constraints are the last line of defence of the rules the Order aggregate keeps: a customer id exactly
        // for an order of a customer, and net + vat = gross of the totals. The number and the Idempotency-Key are
        // unique; the unique index of the key refuses a second order placed at the same time from another cart.
        $this->addSql("CREATE TABLE ordering_order (id UUID NOT NULL, number VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, owner_type VARCHAR(16) NOT NULL, customer_id VARCHAR(255) DEFAULT NULL, email VARCHAR(254) NOT NULL, full_name VARCHAR(100) NOT NULL, phone VARCHAR(12) NOT NULL, street VARCHAR(100) NOT NULL, postal_code CHAR(6) NOT NULL, city VARCHAR(60) NOT NULL, country CHAR(2) NOT NULL, currency CHAR(3) NOT NULL, total_net BIGINT NOT NULL, total_vat BIGINT NOT NULL, total_gross BIGINT NOT NULL, idempotency_key VARCHAR(100) NOT NULL, request_fingerprint CHAR(64) NOT NULL, placed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, confirmation_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id), CONSTRAINT ordering_order_owner_check CHECK ((owner_type = 'customer') = (customer_id IS NOT NULL)), CONSTRAINT ordering_order_totals_check CHECK (total_net + total_vat = total_gross))");
        $this->addSql('CREATE UNIQUE INDEX ordering_order_number_key ON ordering_order (number)');
        $this->addSql('CREATE UNIQUE INDEX ordering_order_idempotency_key_key ON ordering_order (idempotency_key)');
        // Lines are kept with their order for ever: the foreign key has no ON DELETE CASCADE.
        $this->addSql('CREATE TABLE ordering_order_line (id UUID NOT NULL, order_id UUID NOT NULL, position INT NOT NULL, variant_id UUID NOT NULL, product_id UUID NOT NULL, product_name VARCHAR(255) NOT NULL, variant_label VARCHAR(255) NOT NULL, quantity INT NOT NULL, unit_net INT NOT NULL, unit_vat INT NOT NULL, unit_gross INT NOT NULL, vat_rate_bp INT NOT NULL, tax_class VARCHAR(50) NOT NULL, line_net BIGINT NOT NULL, line_vat BIGINT NOT NULL, line_gross BIGINT NOT NULL, PRIMARY KEY (id), CONSTRAINT ordering_order_line_quantity_check CHECK (quantity BETWEEN 1 AND 99))');
        $this->addSql('CREATE INDEX ordering_order_line_order_id_idx ON ordering_order_line (order_id)');
        $this->addSql('CREATE UNIQUE INDEX ordering_order_line_position_key ON ordering_order_line (order_id, position)');
        $this->addSql('CREATE UNIQUE INDEX ordering_order_line_variant_key ON ordering_order_line (order_id, variant_id)');
        $this->addSql('ALTER TABLE ordering_order_line ADD CONSTRAINT ordering_order_line_order_fk FOREIGN KEY (order_id) REFERENCES ordering_order (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // The lines first: they reference the order table through the foreign key.
        $this->addSql('DROP TABLE ordering_order_line');
        $this->addSql('DROP TABLE ordering_order');
        $this->addSql('DROP SEQUENCE ordering_order_number_seq');
    }
}
