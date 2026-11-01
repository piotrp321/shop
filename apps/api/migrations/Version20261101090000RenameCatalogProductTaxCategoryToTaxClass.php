<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101090000RenameCatalogProductTaxCategoryToTaxClass extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename catalog_product.tax_category to tax_class and map the old categories to the new tax classes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product RENAME COLUMN tax_category TO tax_class');
        $this->addSql(<<<'SQL'
            UPDATE catalog_product SET tax_class = CASE tax_class
                WHEN 'printed_publication' THEN 'printed_book'
                WHEN 'food' THEN 'freeze_dried_meal'
                WHEN 'food_supplement' THEN 'protein_powder'
                ELSE tax_class
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Lossy: several classes map to one old category. The exact classes are restored by `make seed`.
        $this->addSql(<<<'SQL'
            UPDATE catalog_product SET tax_class = CASE tax_class
                WHEN 'printed_book' THEN 'printed_publication'
                WHEN 'printed_map' THEN 'printed_publication'
                WHEN 'freeze_dried_meal' THEN 'food'
                WHEN 'energy_bar' THEN 'food'
                WHEN 'energy_gel' THEN 'food'
                WHEN 'protein_powder' THEN 'food_supplement'
                WHEN 'electrolyte_tablet' THEN 'food_supplement'
                ELSE tax_class
            END
            SQL);
        $this->addSql('ALTER TABLE catalog_product RENAME COLUMN tax_class TO tax_category');
    }
}
