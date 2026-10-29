<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029101125CreateCatalogProduct extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the catalog product and product category assignment tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_product (slug VARCHAR(200) NOT NULL, name VARCHAR(200) NOT NULL, short_description VARCHAR(500) NOT NULL, tax_category VARCHAR(32) NOT NULL, images JSONB NOT NULL, id UUID NOT NULL, primary_category_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX catalog_product_primary_category_id_idx ON catalog_product (primary_category_id)');
        $this->addSql('CREATE UNIQUE INDEX catalog_product_slug_key ON catalog_product (slug)');
        $this->addSql('CREATE TABLE catalog_product_category (product_id UUID NOT NULL, category_id UUID NOT NULL, PRIMARY KEY (product_id, category_id))');
        $this->addSql('CREATE INDEX IDX_8BEBEF264584665A ON catalog_product_category (product_id)');
        $this->addSql('CREATE INDEX IDX_8BEBEF2612469DE2 ON catalog_product_category (category_id)');
        $this->addSql('ALTER TABLE catalog_product ADD CONSTRAINT FK_DCF8F981B6A9FD63 FOREIGN KEY (primary_category_id) REFERENCES catalog_category (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE catalog_product_category ADD CONSTRAINT FK_8BEBEF264584665A FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE catalog_product_category ADD CONSTRAINT FK_8BEBEF2612469DE2 FOREIGN KEY (category_id) REFERENCES catalog_category (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product DROP CONSTRAINT FK_DCF8F981B6A9FD63');
        $this->addSql('ALTER TABLE catalog_product_category DROP CONSTRAINT FK_8BEBEF264584665A');
        $this->addSql('ALTER TABLE catalog_product_category DROP CONSTRAINT FK_8BEBEF2612469DE2');
        $this->addSql('DROP TABLE catalog_product');
        $this->addSql('DROP TABLE catalog_product_category');
    }
}
