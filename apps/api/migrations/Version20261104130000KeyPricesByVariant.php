<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104130000KeyPricesByVariant extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key prices and their history by product variant: pricing_product_price becomes pricing_variant_price and product_id becomes variant_id; every value and id stays.';
    }

    public function up(Schema $schema): void
    {
        // The Catalog migration before this one gave every product a default variant with the id of the product, so
        // the stored product ids are the ids of those default variants: only names change.
        $this->addSql('ALTER TABLE pricing_product_price RENAME TO pricing_variant_price');
        $this->addSql('ALTER TABLE pricing_variant_price RENAME COLUMN product_id TO variant_id');
        $this->addSql('ALTER TABLE pricing_variant_price RENAME CONSTRAINT pricing_product_price_pkey TO pricing_variant_price_pkey');
        $this->addSql('ALTER TABLE pricing_price_history RENAME COLUMN product_id TO variant_id');
        $this->addSql('ALTER INDEX pricing_price_history_product_changed_at_idx RENAME TO pricing_price_history_variant_changed_at_idx');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX pricing_price_history_variant_changed_at_idx RENAME TO pricing_price_history_product_changed_at_idx');
        $this->addSql('ALTER TABLE pricing_price_history RENAME COLUMN variant_id TO product_id');
        $this->addSql('ALTER TABLE pricing_variant_price RENAME CONSTRAINT pricing_variant_price_pkey TO pricing_product_price_pkey');
        $this->addSql('ALTER TABLE pricing_variant_price RENAME COLUMN variant_id TO product_id');
        $this->addSql('ALTER TABLE pricing_variant_price RENAME TO pricing_product_price');
    }
}
