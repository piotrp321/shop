<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The runs of the asynchronous full rebuild of the search index and their counted batches
 * (docs/features/async-indexing/spec.md, "Przebieg"): new, empty tables, so one transactional migration does. The partial
 * unique index allows one active run (finished_at not set) per document type.
 */
final class Version20261128120000CreateSearchRebuildRuns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'catalog_search_rebuild_run and catalog_search_rebuild_batch: the runs of catalog:reindex --async.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_search_rebuild_batch (batch INT NOT NULL, documents INT NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (run_id, batch))');
        $this->addSql('CREATE INDEX IDX_D20FB25984E3FEC4 ON catalog_search_rebuild_batch (run_id)');
        $this->addSql('CREATE TABLE catalog_search_rebuild_run (status VARCHAR(16) NOT NULL, total_batches INT DEFAULT NULL, done_batches INT NOT NULL, documents BIGINT NOT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, failure TEXT DEFAULT NULL, id UUID NOT NULL, document_type VARCHAR(32) NOT NULL, target_index VARCHAR(255) NOT NULL, previous_index VARCHAR(255) NOT NULL, batch_size INT NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, heartbeat_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX catalog_search_rebuild_run_active_uniq ON catalog_search_rebuild_run (document_type) WHERE (finished_at IS NULL)');
        $this->addSql('ALTER TABLE catalog_search_rebuild_batch ADD CONSTRAINT FK_D20FB25984E3FEC4 FOREIGN KEY (run_id) REFERENCES catalog_search_rebuild_run (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_search_rebuild_batch DROP CONSTRAINT FK_D20FB25984E3FEC4');
        $this->addSql('DROP TABLE catalog_search_rebuild_batch');
        $this->addSql('DROP TABLE catalog_search_rebuild_run');
    }
}
