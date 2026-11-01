<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101130000AddCatalogProductNameIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index product names for the price list of the panel: trigram GIN for substring search, (name, id) for keyset paging.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        // Operator class of the trigram index (ILIKE '%…%'); the Doctrine mapping cannot express it.
        $this->addSql('CREATE INDEX catalog_product_name_trgm_idx ON catalog_product USING gin (name gin_trgm_ops)');
        $this->addSql('CREATE INDEX catalog_product_name_id_idx ON catalog_product (name, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX catalog_product_name_id_idx');
        $this->addSql('DROP INDEX catalog_product_name_trgm_idx');
        // The extension stays: dropping it could break other users of it.
    }
}
