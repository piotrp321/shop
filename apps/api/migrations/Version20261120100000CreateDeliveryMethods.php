<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The delivery methods of the module Delivery (docs/features/delivery/spec.md, "Moduł Delivery"): a new, empty table.
 * The name is unique without regard to case through an index on lower(name) (A9), which the mapping cannot say; the
 * quote reads the active methods along (active, position).
 */
final class Version20261120100000CreateDeliveryMethods extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'delivery_method: the delivery methods with their price, threshold of free delivery, position and tracking template.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE delivery_method (id UUID NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(200) NOT NULL, price_gross BIGINT NOT NULL, free_from_gross BIGINT DEFAULT NULL, active BOOLEAN NOT NULL, position SMALLINT NOT NULL, tracking_url_template VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX delivery_method_name_key ON delivery_method (lower(name))');
        $this->addSql('CREATE INDEX delivery_method_active_position_idx ON delivery_method (active, position)');
        $this->addSql('ALTER TABLE delivery_method ADD CONSTRAINT delivery_method_price_gross_check CHECK (price_gross BETWEEN 0 AND 99999999)');
        $this->addSql('ALTER TABLE delivery_method ADD CONSTRAINT delivery_method_free_from_gross_check CHECK (free_from_gross IS NULL OR free_from_gross BETWEEN 1 AND 99999999999)');
        $this->addSql('ALTER TABLE delivery_method ADD CONSTRAINT delivery_method_position_check CHECK (position BETWEEN 0 AND 999)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE delivery_method');
    }
}
