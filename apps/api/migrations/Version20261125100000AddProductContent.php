<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125100000AddProductContent extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the brand, the description and the parameters of a product (docs/features/product-data/spec.md).';
    }

    public function up(Schema $schema): void
    {
        // Columns with a constant default only change the catalogue (no rewrite of the table, whatever its size): the
        // products stored before have no brand, no description and no parameters.
        $this->addSql("ALTER TABLE catalog_product ADD brand VARCHAR(60) DEFAULT '' NOT NULL, ADD description TEXT DEFAULT '' NOT NULL, ADD parameters JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product DROP brand, DROP description, DROP parameters');
    }
}
