<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107150000AddBackofficeOrderListIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the orders for the list of the panel: newest first (also by status), the email regardless of letter case, the pending refunds by due date.';
    }

    public function up(Schema $schema): void
    {
        // The keyset order of the list (placed_at DESC, id DESC), alone and after the status filter.
        $this->addSql('CREATE INDEX ordering_order_placed_at_id_idx ON ordering_order (placed_at DESC, id DESC)');
        $this->addSql('CREATE INDEX ordering_order_status_placed_at_id_idx ON ordering_order (status, placed_at DESC, id DESC)');
        // The exact search by email regardless of letter case (lower(email) = lower(:email)); the mapping cannot
        // express an index on an expression.
        $this->addSql('CREATE INDEX ordering_order_lower_email_idx ON ordering_order (lower(email))');
        // The refunds to do, nearest due date first (refund_due_at ASC, id ASC).
        $this->addSql('CREATE INDEX ordering_order_pending_refund_idx ON ordering_order (refund_due_at, id) WHERE refund_amount IS NOT NULL AND refund_completed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ordering_order_pending_refund_idx');
        $this->addSql('DROP INDEX ordering_order_lower_email_idx');
        $this->addSql('DROP INDEX ordering_order_status_placed_at_id_idx');
        $this->addSql('DROP INDEX ordering_order_placed_at_id_idx');
    }
}
