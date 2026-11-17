<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The management of the category tree (spec ADMIN-FEATURES, 4): the place of a category among its siblings, its version
 * and the redirects of old addresses. Schema only: every existing category gets position 0 and version 1 from the
 * defaults, so the tree keeps its order by name. catalog_category is small, so one transactional migration does.
 */
final class Version20261115170000AddCategoryOrderAndRedirects extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'catalog_category: position and version; catalog_category_redirect: the old addresses of categories.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_category ADD position INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE catalog_category ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE TABLE catalog_category_redirect (old_path TEXT NOT NULL, category_id UUID NOT NULL, PRIMARY KEY (old_path))');
        $this->addSql('CREATE INDEX catalog_category_redirect_category_id_idx ON catalog_category_redirect (category_id)');
        $this->addSql('ALTER TABLE catalog_category_redirect ADD CONSTRAINT catalog_category_redirect_category_id_fkey FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_category_redirect');
        $this->addSql('ALTER TABLE catalog_category DROP position');
        $this->addSql('ALTER TABLE catalog_category DROP version');
    }
}
