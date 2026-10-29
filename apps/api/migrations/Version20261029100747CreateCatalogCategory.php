<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029100747CreateCatalogCategory extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the catalog category table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_category (name VARCHAR(100) NOT NULL, path TEXT NOT NULL, id UUID NOT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX catalog_category_parent_id_idx ON catalog_category (parent_id)');
        // Operator class for subtree queries (path LIKE '/…/<id>/%'); Doctrine mapping cannot express it.
        $this->addSql('CREATE INDEX catalog_category_path_idx ON catalog_category (path text_pattern_ops)');
        $this->addSql('ALTER TABLE catalog_category ADD CONSTRAINT FK_349BC7DF727ACA70 FOREIGN KEY (parent_id) REFERENCES catalog_category (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_category DROP CONSTRAINT FK_349BC7DF727ACA70');
        $this->addSql('DROP TABLE catalog_category');
    }
}
