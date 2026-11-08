<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108190000CreateDiscountCodes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the discount codes of Pricing and their usages.';
    }

    public function up(Schema $schema): void
    {
        // A discount code: the normalised (upper-case) text, unique for ever; a percent or an amount, never both; an
        // amount code has a minimum above its amount; valid in [starts_at, ends_at), whole seconds, ends_at NULL for
        // ever. An actor is the staff member (both the id and the name) or the system (neither, the demo data).
        $this->addSql("CREATE TABLE pricing_discount_code (id UUID NOT NULL, code VARCHAR(32) NOT NULL, kind VARCHAR(8) NOT NULL, percent_off SMALLINT DEFAULT NULL, amount_gross INT DEFAULT NULL, minimum_order_gross BIGINT DEFAULT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, usage_limit INT DEFAULT NULL, enabled BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_by_id VARCHAR(255) DEFAULT NULL, created_by_name VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_by_id VARCHAR(255) DEFAULT NULL, updated_by_name VARCHAR(255) DEFAULT NULL, version BIGINT DEFAULT 1 NOT NULL, PRIMARY KEY (id), "
            ."CONSTRAINT pricing_discount_code_value_check CHECK ((kind = 'percent' AND percent_off IS NOT NULL AND amount_gross IS NULL) OR (kind = 'amount' AND amount_gross IS NOT NULL AND percent_off IS NULL)), "
            .'CONSTRAINT pricing_discount_code_percent_off_check CHECK (percent_off BETWEEN 1 AND 90), '
            ."CONSTRAINT pricing_discount_code_minimum_check CHECK (kind <> 'amount' OR (minimum_order_gross IS NOT NULL AND minimum_order_gross > amount_gross)), "
            .'CONSTRAINT pricing_discount_code_period_check CHECK (starts_at < ends_at), '
            .'CONSTRAINT pricing_discount_code_actor_check CHECK ((created_by_id IS NULL) = (created_by_name IS NULL) AND (updated_by_id IS NULL) = (updated_by_name IS NULL)))');
        $this->addSql('CREATE UNIQUE INDEX pricing_discount_code_code_key ON pricing_discount_code (code)');
        // The keyset order of the list of the panel (created_at DESC, id).
        $this->addSql('CREATE INDEX pricing_discount_code_created_at_id_idx ON pricing_discount_code (created_at DESC, id)');
        // A usage of a code by an order, without a foreign key to Ordering (a module boundary); an order has at most
        // one. Codes are never deleted, so the foreign key to the code has no ON DELETE.
        $this->addSql('CREATE TABLE pricing_discount_code_usage (id UUID NOT NULL, code_id UUID NOT NULL, order_id UUID NOT NULL, used_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX pricing_discount_code_usage_order_id_key ON pricing_discount_code_usage (order_id)');
        $this->addSql('CREATE INDEX pricing_discount_code_usage_code_id_idx ON pricing_discount_code_usage (code_id)');
        // The active usages of a code are counted at every quote of a code with a limit.
        $this->addSql('CREATE INDEX pricing_discount_code_usage_active_idx ON pricing_discount_code_usage (code_id) WHERE released_at IS NULL');
        $this->addSql('ALTER TABLE pricing_discount_code_usage ADD CONSTRAINT pricing_discount_code_usage_code_fk FOREIGN KEY (code_id) REFERENCES pricing_discount_code (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pricing_discount_code_usage');
        $this->addSql('DROP TABLE pricing_discount_code');
    }
}
