<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100100ValidateOrderDeliveryChecks extends AbstractMigration
{
    private const array CHECKS = [
        'ordering_order_delivery_method_check',
        'ordering_order_delivery_template_check',
        'ordering_order_delivery_amounts_check',
        'ordering_order_delivery_cost_check',
        'ordering_order_tracking_number_check',
    ];

    public function getDescription(): string
    {
        return 'Validate the CHECK constraints of the delivery of orders added NOT VALID by Version20261121100000AddOrderDelivery.';
    }

    public function up(Schema $schema): void
    {
        // A scan of ordering_order under SHARE UPDATE EXCLUSIVE: reads and writes of orders go on (apps/api/CLAUDE.md).
        foreach (self::CHECKS as $check) {
            $this->addSql(\sprintf('ALTER TABLE ordering_order VALIDATE CONSTRAINT %s', $check));
        }
    }

    public function down(Schema $schema): void
    {
        // A validated constraint cannot be made NOT VALID again; the down() of Version20261121100000AddOrderDelivery
        // drops the constraints with their columns.
        $this->addSql('SELECT 1');
    }
}
