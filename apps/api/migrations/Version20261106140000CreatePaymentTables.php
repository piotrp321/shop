<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106140000CreatePaymentTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the payments of orders and the handled webhook events of the providers.';
    }

    public function up(Schema $schema): void
    {
        // No foreign keys to Ordering: a payment keeps a copy of the order id. The CHECK constraints keep what the
        // Payment aggregate keeps: a positive amount, the known providers, statuses and refund reasons, the time of
        // execution exactly for a succeeded payment and a refund reason only for one.
        $this->addSql("CREATE TABLE payment_payment (id UUID NOT NULL, order_id UUID NOT NULL, provider VARCHAR(20) NOT NULL, amount BIGINT NOT NULL, currency CHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, provider_payment_id VARCHAR(100) DEFAULT NULL, redirect_url VARCHAR(500) DEFAULT NULL, idempotency_key VARCHAR(100) NOT NULL, request_fingerprint CHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, executed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, refund_required_reason VARCHAR(30) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT payment_payment_amount_check CHECK (amount > 0), CONSTRAINT payment_payment_provider_check CHECK (provider IN ('demo-transfer', 'demo-card')), CONSTRAINT payment_payment_status_check CHECK (status IN ('pending', 'succeeded', 'failed', 'cancelled')), CONSTRAINT payment_payment_refund_reason_check CHECK (refund_required_reason IS NULL OR refund_required_reason IN ('order_cancelled', 'paid_after_deadline', 'duplicate')), CONSTRAINT payment_payment_refund_succeeded_check CHECK (refund_required_reason IS NULL OR status = 'succeeded'), CONSTRAINT payment_payment_executed_check CHECK ((status = 'succeeded') = (executed_at IS NOT NULL)))");
        $this->addSql('CREATE UNIQUE INDEX payment_payment_idempotency_key_key ON payment_payment (idempotency_key)');
        $this->addSql('CREATE UNIQUE INDEX payment_payment_provider_payment_key ON payment_payment (provider, provider_payment_id)');
        // At most one pending payment per order: two concurrent starts with different keys cannot both write one.
        $this->addSql("CREATE UNIQUE INDEX payment_payment_one_pending_per_order ON payment_payment (order_id) WHERE status = 'pending'");
        $this->addSql('CREATE INDEX payment_payment_order_created_idx ON payment_payment (order_id, created_at)');
        // Handled webhook events, kept forever: the deduplication of the deliveries.
        $this->addSql('CREATE TABLE payment_webhook_event (provider VARCHAR(20) NOT NULL, event_id VARCHAR(100) NOT NULL, payment_id UUID NOT NULL, received_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (provider, event_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_webhook_event');
        $this->addSql('DROP TABLE payment_payment');
    }
}
