<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107140000AddOrderLifecycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the lifecycle of orders after the payment: shipped, delivered, returned, the cancellation by staff, the refund and the history of the statuses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ordering_order ADD shipped_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD delivered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD returned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD refund_amount BIGINT DEFAULT NULL, ADD refund_due_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD refund_completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD refund_completed_by_staff_id VARCHAR(255) DEFAULT NULL, ADD refund_completed_by_username VARCHAR(255) DEFAULT NULL, ADD refund_note VARCHAR(500) DEFAULT NULL');
        // The payment stays recorded after a cancellation by staff and a return, so "paid exactly when paid_at" gives
        // way to: paid_at together with payment_id, exactly for an order paid for (paid and every status after it, and
        // a cancellation by staff, which only a paid order gets). The rule of a cancelled order stays as it is.
        $this->addSql('ALTER TABLE ordering_order DROP CONSTRAINT ordering_order_paid_check');
        $this->addSql('ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_payment_check CHECK ((paid_at IS NULL) = (payment_id IS NULL))');
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_paid_check CHECK ((paid_at IS NOT NULL) = (status IN ('paid', 'shipped', 'delivered', 'returned') OR cancellation_reason IS NOT DISTINCT FROM 'staff'))");
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_shipped_check CHECK ((shipped_at IS NOT NULL) = (status IN ('shipped', 'delivered', 'returned')))");
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_delivered_check CHECK (delivered_at IS NULL OR status IN ('delivered', 'returned'))");
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_returned_check CHECK ((returned_at IS NOT NULL) = (status = 'returned'))");
        // A refund (amount and due date) exactly for a returned order and one cancelled by staff; its completion (the
        // moment with the staff member, the note only with them) only with a refund.
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_refund_check CHECK ((refund_amount IS NULL) = (refund_due_at IS NULL) AND (refund_amount IS NOT NULL) = (status = 'returned' OR cancellation_reason IS NOT DISTINCT FROM 'staff') AND (refund_amount IS NULL OR refund_amount >= 0))");
        $this->addSql('ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_refund_completed_check CHECK ((refund_completed_at IS NULL) = (refund_completed_by_staff_id IS NULL) AND (refund_completed_at IS NULL) = (refund_completed_by_username IS NULL) AND (refund_completed_at IS NULL OR refund_amount IS NOT NULL) AND (refund_note IS NULL OR refund_completed_at IS NOT NULL))');

        // The history of the statuses, kept with the order for ever (the foreign key has no ON DELETE CASCADE).
        $this->addSql("CREATE TABLE ordering_order_status_change (order_id UUID NOT NULL, position INT NOT NULL, from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, actor_type VARCHAR(16) NOT NULL, staff_id VARCHAR(255) DEFAULT NULL, staff_username VARCHAR(255) DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, PRIMARY KEY (order_id, position), CONSTRAINT ordering_order_status_change_position_check CHECK (position >= 0), CONSTRAINT ordering_order_status_change_actor_check CHECK (actor_type IN ('system', 'customer', 'staff') AND (actor_type = 'staff') = (staff_id IS NOT NULL) AND (staff_id IS NULL) = (staff_username IS NULL)))");
        $this->addSql('CREATE INDEX ordering_order_status_change_order_id_idx ON ordering_order_status_change (order_id)');
        $this->addSql('ALTER TABLE ordering_order_status_change ADD CONSTRAINT ordering_order_status_change_order_fk FOREIGN KEY (order_id) REFERENCES ordering_order (id) NOT DEFERRABLE');
        // Existing orders (new, paid, cancelled for want of a payment) get the history their moments tell: the
        // placement by the customer, then the payment or the expiry by the system.
        $this->addSql("INSERT INTO ordering_order_status_change (order_id, position, from_status, to_status, occurred_at, actor_type)
            SELECT id, 0, NULL, 'new', placed_at, 'customer' FROM ordering_order
            UNION ALL SELECT id, 1, 'new', 'paid', paid_at, 'system' FROM ordering_order WHERE paid_at IS NOT NULL
            UNION ALL SELECT id, 1, 'new', 'cancelled', cancelled_at, 'system' FROM ordering_order WHERE cancelled_at IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Only orders of the payment cycle fit the rule put back: an order shipped, returned or cancelled by staff
        // makes it fail.
        $this->addSql('DROP TABLE ordering_order_status_change');
        $this->addSql('ALTER TABLE ordering_order DROP CONSTRAINT ordering_order_payment_check, DROP CONSTRAINT ordering_order_paid_check');
        // Dropping the columns drops the CHECK constraints on them.
        $this->addSql('ALTER TABLE ordering_order DROP shipped_at, DROP delivered_at, DROP returned_at, DROP refund_amount, DROP refund_due_at, DROP refund_completed_at, DROP refund_completed_by_staff_id, DROP refund_completed_by_username, DROP refund_note');
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_paid_check CHECK ((status = 'paid') = (paid_at IS NOT NULL AND payment_id IS NOT NULL))");
    }
}
