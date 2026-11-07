<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000AddCatalogProductStatus extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give every product a status (existing ones published at the time of the migration) and the time and actor of its last change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product ADD status VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_product ADD first_published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_product ADD updated_by VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE catalog_product ADD updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Every product so far is in the storefront, so it is published; no staff member made the change.
        $this->addSql("UPDATE catalog_product SET status = 'published', first_published_at = now(), updated_at = now()");
        $this->addSql('ALTER TABLE catalog_product ALTER status SET NOT NULL');
        $this->addSql('ALTER TABLE catalog_product ALTER updated_at SET NOT NULL');
        // The panel lists drafts by name; there are few of them, so the index holds only drafts.
        $this->addSql("CREATE INDEX catalog_product_draft_name_id_idx ON catalog_product (name, id) WHERE (status = 'draft')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX catalog_product_draft_name_id_idx');
        $this->addSql('ALTER TABLE catalog_product DROP updated_at');
        $this->addSql('ALTER TABLE catalog_product DROP updated_by');
        $this->addSql('ALTER TABLE catalog_product DROP first_published_at');
        $this->addSql('ALTER TABLE catalog_product DROP status');
    }
}
