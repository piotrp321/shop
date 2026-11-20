<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100000AddOrderDelivery extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the delivery to orders (the copy of the method, its cost, the tracking number) and the table of its VAT shares; the CHECK constraints of ordering_order NOT VALID.';
    }

    public function up(Schema $schema): void
    {
        // Nullable columns and columns with a constant default only change the catalogue (no rewrite of the table):
        // existing orders have no method and a delivery of 0 (docs/features/delivery/spec.md, P17).
        $this->addSql('ALTER TABLE ordering_order ADD delivery_method_id UUID, ADD delivery_method_name VARCHAR(100), ADD delivery_tracking_url_template VARCHAR(500), ADD delivery_gross BIGINT DEFAULT 0 NOT NULL, ADD delivery_net BIGINT DEFAULT 0 NOT NULL, ADD delivery_vat BIGINT DEFAULT 0 NOT NULL, ADD tracking_number VARCHAR(40)');
        // NOT VALID: checked for new and changed rows from now on, without scanning the large table under the lock of
        // this migration; Version20261121100100ValidateOrderDeliveryChecks validates them (apps/api/CLAUDE.md).
        $this->addSql('ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_delivery_method_check CHECK ((delivery_method_id IS NULL) = (delivery_method_name IS NULL)) NOT VALID');
        $this->addSql('ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_delivery_template_check CHECK (delivery_tracking_url_template IS NULL OR delivery_method_id IS NOT NULL) NOT VALID');
        $this->addSql('ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_delivery_amounts_check CHECK (delivery_net >= 0 AND delivery_vat >= 0 AND delivery_net + delivery_vat = delivery_gross) NOT VALID');
        $this->addSql('ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_delivery_cost_check CHECK (delivery_method_id IS NOT NULL OR delivery_gross = 0) NOT VALID');
        $this->addSql('ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_tracking_number_check CHECK (tracking_number IS NULL OR shipped_at IS NOT NULL) NOT VALID');
        // A new table: its CHECK is checked at once. The shares of the delivery of an order by VAT rate (for the
        // invoices, INVOICING #771); none for a free delivery and for orders before the delivery.
        $this->addSql('CREATE TABLE ordering_order_delivery_vat (order_id UUID NOT NULL, vat_rate_bp INT NOT NULL, gross BIGINT NOT NULL, net BIGINT NOT NULL, vat BIGINT NOT NULL, PRIMARY KEY (order_id, vat_rate_bp), CONSTRAINT ordering_order_delivery_vat_amounts_check CHECK (net + vat = gross AND gross > 0 AND net >= 0 AND vat >= 0))');
        $this->addSql('CREATE INDEX ordering_order_delivery_vat_order_id_idx ON ordering_order_delivery_vat (order_id)');
        $this->addSql('ALTER TABLE ordering_order_delivery_vat ADD CONSTRAINT ordering_order_delivery_vat_order_fk FOREIGN KEY (order_id) REFERENCES ordering_order (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // Dropping the columns drops the CHECK constraints on them.
        $this->addSql('DROP TABLE ordering_order_delivery_vat');
        $this->addSql('ALTER TABLE ordering_order DROP delivery_method_id, DROP delivery_method_name, DROP delivery_tracking_url_template, DROP delivery_gross, DROP delivery_net, DROP delivery_vat, DROP tracking_number');
    }
}
