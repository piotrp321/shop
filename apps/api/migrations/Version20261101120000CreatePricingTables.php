<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101120000CreatePricingTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the pricing tables: the current price of a product and its append-only history.';
    }

    public function up(Schema $schema): void
    {
        // No foreign keys to the catalog tables: Pricing is a separate module, even in the same database.
        $this->addSql('CREATE TABLE pricing_product_price (product_id UUID NOT NULL, currency VARCHAR(3) NOT NULL, gross_amount INT NOT NULL, net_amount INT NOT NULL, vat_amount INT NOT NULL, vat_rate_bp INT NOT NULL, tax_class VARCHAR(32) NOT NULL, version BIGINT DEFAULT 1 NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (product_id))');
        $this->addSql('CREATE TABLE pricing_price_history (id UUID NOT NULL, product_id UUID NOT NULL, currency VARCHAR(3) NOT NULL, gross_amount INT NOT NULL, net_amount INT NOT NULL, vat_amount INT NOT NULL, vat_rate_bp INT NOT NULL, tax_class VARCHAR(32) NOT NULL, changed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, source VARCHAR(32) NOT NULL, actor_id VARCHAR(255) DEFAULT NULL, actor_name VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX pricing_price_history_product_changed_at_idx ON pricing_price_history (product_id, changed_at DESC, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pricing_price_history');
        $this->addSql('DROP TABLE pricing_product_price');
    }
}
