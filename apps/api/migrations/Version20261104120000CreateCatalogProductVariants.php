<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000CreateCatalogProductVariants extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the variant axes of products and the variants table; every existing product gets one default variant with its own id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE catalog_product ADD variant_axes JSONB DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE catalog_product ALTER variant_axes DROP DEFAULT');
        // values_key is the lower case of the canonical jsonb text of option_values, computed by PostgreSQL: the two
        // unique indexes then reject a repeated position and a repeated combination of values (without regard to
        // case, also for Polish letters) whoever writes the row.
        $this->addSql('CREATE TABLE catalog_product_variant (id UUID NOT NULL, product_id UUID NOT NULL, position INT NOT NULL, option_values JSONB NOT NULL, values_key TEXT GENERATED ALWAYS AS (lower(option_values::text)) STORED NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX catalog_product_variant_position_key ON catalog_product_variant (product_id, position)');
        $this->addSql('CREATE UNIQUE INDEX catalog_product_variant_values_key ON catalog_product_variant (product_id, values_key)');
        $this->addSql('ALTER TABLE catalog_product_variant ADD CONSTRAINT catalog_product_variant_product_fk FOREIGN KEY (product_id) REFERENCES catalog_product (id) ON DELETE CASCADE NOT DEFERRABLE');
        // The id of the default variant is the id of its product: Pricing, Inventory and Cart keep product ids today,
        // and after their migrations those ids are variant ids.
        $this->addSql("INSERT INTO catalog_product_variant (id, product_id, position, option_values) SELECT id, id, 0, '[]'::jsonb FROM catalog_product");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_product_variant');
        $this->addSql('ALTER TABLE catalog_product DROP variant_axes');
    }
}
