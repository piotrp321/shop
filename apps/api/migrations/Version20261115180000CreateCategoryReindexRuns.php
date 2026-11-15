<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The runs of the reindexing of category subtrees (spec ADMIN-FEATURES, 4 "Odświeżenie sklepu i reindeksacja"): a new,
 * empty table, so one transactional migration does. No index besides the key: a batch reads its run by the id.
 */
final class Version20261115180000CreateCategoryReindexRuns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'catalog_category_reindex: the runs of the batch chain that reindexes a category subtree.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_category_reindex (id UUID NOT NULL, category_id UUID NOT NULL, cursor UUID DEFAULT NULL, next_batch INT NOT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog_category_reindex');
    }
}
