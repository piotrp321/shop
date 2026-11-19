<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119100000CreateUnpaidOrderClaims extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the claims of the unpaid orders: the address and the identity an order counts to until its expiry.';
    }

    public function up(Schema $schema): void
    {
        // One row per new order, removed when it is paid or cancelled; the foreign key cascades, as the claim is worth
        // nothing without its order. The table starts empty: orders placed before it count to no limit and expire
        // within 35 minutes.
        $this->addSql('CREATE TABLE ordering_unpaid_order_claim (order_id UUID NOT NULL, address_key VARCHAR(64) NOT NULL, identity_key VARCHAR(264) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (order_id))');
        $this->addSql('CREATE INDEX ordering_unpaid_order_claim_address_idx ON ordering_unpaid_order_claim (address_key, expires_at)');
        $this->addSql('CREATE INDEX ordering_unpaid_order_claim_identity_idx ON ordering_unpaid_order_claim (identity_key, expires_at)');
        $this->addSql('ALTER TABLE ordering_unpaid_order_claim ADD CONSTRAINT ordering_unpaid_order_claim_order_fk FOREIGN KEY (order_id) REFERENCES ordering_order (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ordering_unpaid_order_claim');
    }
}
