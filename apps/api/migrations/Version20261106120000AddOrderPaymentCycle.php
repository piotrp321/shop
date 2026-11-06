<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000AddOrderPaymentCycle extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the payment cycle of orders: the deadline, the access token of a guest, paid and cancelled, the markers of their emails.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ordering_order ADD payment_deadline TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD access_token VARCHAR(43) DEFAULT NULL, ADD paid_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD payment_id UUID DEFAULT NULL, ADD cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD cancellation_reason VARCHAR(30) DEFAULT NULL, ADD payment_notification_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD cancellation_notification_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Existing orders: the deadline the domain gives (30 minutes after the placement) and, for a guest, a token of
        // 43 characters of base64url (gen_random_uuid() and sha256() are built into PostgreSQL 18, no pgcrypto).
        // They have no ExpireUnpaidOrder message; `make seed` removes them locally.
        $this->addSql("UPDATE ordering_order SET payment_deadline = placed_at + interval '30 minutes'");
        $this->addSql("UPDATE ordering_order SET access_token = translate(rtrim(encode(sha256(gen_random_uuid()::text::bytea), 'base64'), '='), '+/', '-_') WHERE owner_type = 'guest'");
        $this->addSql('ALTER TABLE ordering_order ALTER payment_deadline SET NOT NULL');
        // The last line of defence of the rules the Order aggregate keeps: a token exactly for a guest order, the
        // payment exactly for a paid one, the cancellation exactly for a cancelled one.
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_access_token_check CHECK ((owner_type = 'guest') = (access_token IS NOT NULL))");
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_paid_check CHECK ((status = 'paid') = (paid_at IS NOT NULL AND payment_id IS NOT NULL))");
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_cancelled_check CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        // Dropping the columns drops their CHECK constraints with them.
        $this->addSql('ALTER TABLE ordering_order DROP payment_deadline, DROP access_token, DROP paid_at, DROP payment_id, DROP cancelled_at, DROP cancellation_reason, DROP payment_notification_sent_at, DROP cancellation_notification_sent_at');
    }
}
