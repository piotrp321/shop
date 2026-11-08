<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107120000CreateOrderNotifications extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the notifications of orders: the sending lease and the moment each email of an order went out.';
    }

    public function up(Schema $schema): void
    {
        // One row per email of an order, kept with the order for ever (the foreign key has no ON DELETE CASCADE).
        // The CHECK constraints keep the known kinds and a sent email without a claim.
        $this->addSql("CREATE TABLE ordering_order_notification (order_id UUID NOT NULL, kind VARCHAR(20) NOT NULL, claimed_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (order_id, kind), CONSTRAINT ordering_order_notification_kind_check CHECK (kind IN ('confirmation', 'payment_received', 'cancelled', 'shipped', 'return_received')), CONSTRAINT ordering_order_notification_sent_check CHECK (sent_at IS NULL OR claimed_until IS NULL))");
        $this->addSql('CREATE INDEX ordering_order_notification_order_id_idx ON ordering_order_notification (order_id)');
        $this->addSql('ALTER TABLE ordering_order_notification ADD CONSTRAINT ordering_order_notification_order_fk FOREIGN KEY (order_id) REFERENCES ordering_order (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ordering_order_notification');
    }
}
