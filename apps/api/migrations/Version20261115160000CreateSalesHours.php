<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The hourly sales of the dashboard: a small new table, filled after the rollout by ordering:read-models:rebuild (a
 * step of app:backfill) and from then on by every payment.
 */
final class Version20261115160000CreateSalesHours extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ordering_sales_hour: the number and the gross total of the orders paid in every hour, in 16 shards.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ordering_sales_hour (hour_start TIMESTAMP(0) WITH TIME ZONE NOT NULL, shard SMALLINT NOT NULL, order_count INT NOT NULL, gross BIGINT NOT NULL, PRIMARY KEY (hour_start, shard))');
        $this->addSql('ALTER TABLE ordering_sales_hour ADD CONSTRAINT ordering_sales_hour_shard_check CHECK (shard BETWEEN 0 AND 15)');
        $this->addSql("ALTER TABLE ordering_sales_hour ADD CONSTRAINT ordering_sales_hour_hour_check CHECK (hour_start = date_bin('1 hour', hour_start, TIMESTAMPTZ '2000-01-01 00:00:00+00'))");
        $this->addSql('ALTER TABLE ordering_sales_hour ADD CONSTRAINT ordering_sales_hour_totals_check CHECK (order_count >= 0 AND gross >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ordering_sales_hour');
    }
}
