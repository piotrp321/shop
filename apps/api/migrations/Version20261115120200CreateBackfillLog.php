<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120200CreateBackfillLog extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_backfill: the completed steps of app:backfill, which later deployments skip.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_backfill (name VARCHAR(200) NOT NULL, completed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_backfill');
    }
}
