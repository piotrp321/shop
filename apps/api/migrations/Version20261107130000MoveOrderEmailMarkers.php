<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107130000MoveOrderEmailMarkers extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move the markers of the emails of orders from the order table to the notifications of orders.';
    }

    public function up(Schema $schema): void
    {
        // An email that went out stays sent: its notification has the moment and no claim, so no handler sends it
        // again. The order table and the versions of the orders stay as they are otherwise.
        $this->addSql("INSERT INTO ordering_order_notification (order_id, kind, claimed_until, sent_at)
            SELECT id, 'confirmation', CAST(NULL AS TIMESTAMP(0) WITH TIME ZONE), confirmation_sent_at FROM ordering_order WHERE confirmation_sent_at IS NOT NULL
            UNION ALL SELECT id, 'payment_received', NULL, payment_notification_sent_at FROM ordering_order WHERE payment_notification_sent_at IS NOT NULL
            UNION ALL SELECT id, 'cancelled', NULL, cancellation_notification_sent_at FROM ordering_order WHERE cancellation_notification_sent_at IS NOT NULL");
        $this->addSql('ALTER TABLE ordering_order DROP confirmation_sent_at, DROP payment_notification_sent_at, DROP cancellation_notification_sent_at');
    }

    public function down(Schema $schema): void
    {
        // The markers go back to the order table, and the notifications are left empty, as before the migration.
        $this->addSql('ALTER TABLE ordering_order ADD confirmation_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD payment_notification_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD cancellation_notification_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE ordering_order o SET
            confirmation_sent_at = (SELECT sent_at FROM ordering_order_notification n WHERE n.order_id = o.id AND n.kind = 'confirmation'),
            payment_notification_sent_at = (SELECT sent_at FROM ordering_order_notification n WHERE n.order_id = o.id AND n.kind = 'payment_received'),
            cancellation_notification_sent_at = (SELECT sent_at FROM ordering_order_notification n WHERE n.order_id = o.id AND n.kind = 'cancelled')");
        $this->addSql('DELETE FROM ordering_order_notification');
    }
}
