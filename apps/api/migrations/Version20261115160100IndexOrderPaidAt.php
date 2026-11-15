<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The index of the orders by the moment of their payment, of the paid ones only: ordering:read-models:rebuild finds
 * the next hour with a payment and sums the orders of one hour on it, instead of scanning all orders for every hour.
 *
 * Built CONCURRENTLY, so orders can be placed and paid while a large table is indexed; as in
 * Version20261115130100IndexOrderTotals the migration is not transactional and an INVALID index of the name left by a
 * failed build is dropped first, so a retried Job `migrate` builds it anew.
 */
final class Version20261115160100IndexOrderPaidAt extends AbstractMigration
{
    private const string NAME = 'ordering_order_paid_at_idx';

    public function getDescription(): string
    {
        return 'Partial index by paid_at of the paid orders of ordering_order, built concurrently.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if (true === $this->connection->fetchOne(
            'SELECT NOT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
            [self::NAME],
        )) {
            $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
        }
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.' ON ordering_order (paid_at) WHERE paid_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);
    }
}
