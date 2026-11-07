<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000AddCatalogCategorySlug extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give every category a slug unique among its siblings; existing categories get their id as the slug.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_category ADD slug VARCHAR(60) DEFAULT NULL');
        // The id is in the slug format and unique, so it is a valid slug for every existing category.
        $this->addSql('UPDATE catalog_category SET slug = CAST(id AS TEXT)');
        $this->addSql('ALTER TABLE catalog_category ALTER slug SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX catalog_category_parent_slug_key ON catalog_category (parent_id, slug)');
        // parent_id is NULL for the roots, and NULLs are distinct in the index above, so the roots need their own.
        $this->addSql('CREATE UNIQUE INDEX catalog_category_root_slug_key ON catalog_category (slug) WHERE (parent_id IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX catalog_category_root_slug_key');
        $this->addSql('DROP INDEX catalog_category_parent_slug_key');
        $this->addSql('ALTER TABLE catalog_category DROP slug');
    }
}
