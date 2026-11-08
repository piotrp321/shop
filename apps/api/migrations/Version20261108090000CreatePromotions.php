<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108090000CreatePromotions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the promotions of Pricing and their variants.';
    }

    public function up(Schema $schema): void
    {
        // A promotion: a percent off for a period [starts_at, ends_at), whole seconds. An actor is the staff member
        // (both the id and the name) or the system (neither, the demo data).
        $this->addSql('CREATE TABLE pricing_promotion (id UUID NOT NULL, name VARCHAR(100) NOT NULL, percent_off SMALLINT NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_by_id VARCHAR(255) DEFAULT NULL, created_by_name VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_by_id VARCHAR(255) DEFAULT NULL, updated_by_name VARCHAR(255) DEFAULT NULL, version BIGINT DEFAULT 1 NOT NULL, PRIMARY KEY (id), CONSTRAINT pricing_promotion_period_check CHECK (starts_at < ends_at), CONSTRAINT pricing_promotion_percent_off_check CHECK (percent_off BETWEEN 1 AND 90), CONSTRAINT pricing_promotion_actor_check CHECK ((created_by_id IS NULL) = (created_by_name IS NULL) AND (updated_by_id IS NULL) = (updated_by_name IS NULL)))');
        // The keyset order of the list of the panel (starts_at DESC, id).
        $this->addSql('CREATE INDEX pricing_promotion_starts_at_id_idx ON pricing_promotion (starts_at DESC, id)');
        // The variants of a promotion, without a foreign key to Catalog (a module boundary); they go with the
        // promotion.
        $this->addSql('CREATE TABLE pricing_promotion_variant (promotion_id UUID NOT NULL, variant_id UUID NOT NULL, PRIMARY KEY (promotion_id, variant_id))');
        $this->addSql('CREATE INDEX pricing_promotion_variant_variant_id_idx ON pricing_promotion_variant (variant_id)');
        $this->addSql('CREATE INDEX pricing_promotion_variant_promotion_id_idx ON pricing_promotion_variant (promotion_id)');
        $this->addSql('ALTER TABLE pricing_promotion_variant ADD CONSTRAINT pricing_promotion_variant_promotion_fk FOREIGN KEY (promotion_id) REFERENCES pricing_promotion (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pricing_promotion_variant');
        $this->addSql('DROP TABLE pricing_promotion');
    }
}
