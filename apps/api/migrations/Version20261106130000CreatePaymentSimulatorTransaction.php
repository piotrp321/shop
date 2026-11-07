<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106130000CreatePaymentSimulatorTransaction extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the transactions of the payment simulator (the two fake providers).';
    }

    public function up(Schema $schema): void
    {
        // No foreign keys: the simulator is an external system and knows the shop only by the reference it was given.
        // The CHECK constraints keep what the Transaction entity keeps: the shape of the URLs of each provider and
        // a decision time exactly for a paid or rejected transaction.
        $this->addSql("CREATE TABLE payment_simulator_transaction (id UUID NOT NULL, provider VARCHAR(16) NOT NULL, reference VARCHAR(100) NOT NULL, amount BIGINT NOT NULL, currency CHAR(3) NOT NULL, return_url TEXT DEFAULT NULL, success_url TEXT DEFAULT NULL, cancel_url TEXT DEFAULT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id), CONSTRAINT payment_simulator_transaction_provider_check CHECK (provider IN ('demo-transfer', 'demo-card')), CONSTRAINT payment_simulator_transaction_status_check CHECK (status IN ('open', 'paid', 'rejected', 'cancelled')), CONSTRAINT payment_simulator_transaction_amount_check CHECK (amount > 0), CONSTRAINT payment_simulator_transaction_urls_check CHECK ((provider = 'demo-transfer' AND return_url IS NOT NULL AND success_url IS NULL AND cancel_url IS NULL) OR (provider = 'demo-card' AND return_url IS NULL AND success_url IS NOT NULL AND cancel_url IS NOT NULL)), CONSTRAINT payment_simulator_transaction_decided_check CHECK ((status IN ('paid', 'rejected')) = (decided_at IS NOT NULL)))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_simulator_transaction');
    }
}
