<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The customers of the panel: a new table, empty at the migration, filled after the rollout by
 * ordering:read-models:rebuild (a step of app:backfill) and from then on by every placement and payment of an order of
 * a customer. Its sort indexes are built with it (the table is empty); the trigram indexes of the search are built
 * CONCURRENTLY by Version20261116090100IndexCustomerSearch.
 */
final class Version20261116090000CreateCustomers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ordering_customer: every account of the store with an order, with its contact, numbers and moments of its orders, and its sort indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ordering_customer (customer_id VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL, full_name VARCHAR(100) NOT NULL, phone VARCHAR(12) NOT NULL, order_count INT NOT NULL, paid_count INT NOT NULL, paid_gross BIGINT NOT NULL, first_order_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_order_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_order_id UUID NOT NULL, PRIMARY KEY (customer_id))');
        $this->addSql('ALTER TABLE ordering_customer ADD CONSTRAINT ordering_customer_totals_check CHECK (order_count >= 0 AND paid_count >= 0 AND paid_gross >= 0)');
        $this->addSql('ALTER TABLE ordering_customer ADD CONSTRAINT ordering_customer_moments_check CHECK (first_order_at <= last_order_at)');
        $this->addSql('CREATE INDEX ordering_customer_last_order_at_idx ON ordering_customer (last_order_at DESC, customer_id)');
        $this->addSql('CREATE INDEX ordering_customer_name_idx ON ordering_customer (lower(full_name), customer_id)');
        $this->addSql('CREATE INDEX ordering_customer_order_count_idx ON ordering_customer (order_count DESC, customer_id)');
        $this->addSql('CREATE INDEX ordering_customer_paid_gross_idx ON ordering_customer (paid_gross DESC, customer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ordering_customer');
    }
}
