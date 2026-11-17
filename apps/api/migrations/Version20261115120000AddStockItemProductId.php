<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115120000AddStockItemProductId extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The stock record knows the product of its variant (inventory_stock_item.product_id, NULL until filled).';
    }

    public function up(Schema $schema): void
    {
        // Schema only: a nullable column without a default rewrites nothing. The records are filled after the rollout
        // by app:backfill (inventory:stock-records:sync) and kept by the consumer of the Catalog product events; the
        // previous version of the code, still running during the deployment, writes NULL.
        $this->addSql('ALTER TABLE inventory_stock_item ADD product_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_stock_item DROP product_id');
    }
}
