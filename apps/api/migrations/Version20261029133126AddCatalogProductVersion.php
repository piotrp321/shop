<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029133126AddCatalogProductVersion extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the optimistic locking version to catalog products; existing rows start at 1.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product ADD version BIGINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_product DROP version');
    }
}
