<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125110000AddVariantCodes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the SKU and the EAN of a variant (docs/features/product-data/spec.md); the unique index of the SKU is the next migration.';
    }

    public function up(Schema $schema): void
    {
        // Nullable columns without a default only change the catalogue (no rewrite of the table, whatever its size): the
        // variants stored before have no codes.
        $this->addSql('ALTER TABLE catalog_product_variant ADD sku VARCHAR(40) DEFAULT NULL, ADD ean VARCHAR(14) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product_variant DROP sku, DROP ean');
    }
}
