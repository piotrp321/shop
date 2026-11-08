<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109090000AddOrderDiscounts extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the copy of the discount code to orders, and the discount and the copy of the promotion to their lines.';
    }

    public function up(Schema $schema): void
    {
        // The copy of the code: all four together or none (an order without a code).
        $this->addSql('ALTER TABLE ordering_order ADD discount_code VARCHAR(32) DEFAULT NULL, ADD discount_kind VARCHAR(8) DEFAULT NULL, ADD discount_value INT DEFAULT NULL, ADD discount_gross BIGINT DEFAULT NULL');
        $this->addSql("ALTER TABLE ordering_order ADD CONSTRAINT ordering_order_discount_check CHECK ((discount_code IS NULL) = (discount_kind IS NULL) AND (discount_code IS NULL) = (discount_value IS NULL) AND (discount_code IS NULL) = (discount_gross IS NULL) AND (discount_kind IS NULL OR discount_kind IN ('percent', 'amount')) AND (discount_value IS NULL OR discount_value >= 1) AND (discount_gross IS NULL OR discount_gross >= 1))");
        // Existing lines get no discount and no promotion; their stored net, VAT and gross stay as they are (D8).
        $this->addSql('ALTER TABLE ordering_order_line ADD discount_gross BIGINT DEFAULT 0 NOT NULL, ADD promotion_id UUID DEFAULT NULL, ADD promotion_name VARCHAR(100) DEFAULT NULL, ADD promotion_percent_off SMALLINT DEFAULT NULL, ADD regular_unit_gross INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering_order_line ADD CONSTRAINT ordering_order_line_discount_check CHECK (discount_gross >= 0)');
        $this->addSql('ALTER TABLE ordering_order_line ADD CONSTRAINT ordering_order_line_promotion_check CHECK ((promotion_id IS NULL) = (promotion_name IS NULL) AND (promotion_id IS NULL) = (promotion_percent_off IS NULL) AND (promotion_id IS NULL) = (regular_unit_gross IS NULL))');
    }

    public function down(Schema $schema): void
    {
        // Dropping the columns drops the CHECK constraints on them.
        $this->addSql('ALTER TABLE ordering_order_line DROP discount_gross, DROP promotion_id, DROP promotion_name, DROP promotion_percent_off, DROP regular_unit_gross');
        $this->addSql('ALTER TABLE ordering_order DROP discount_code, DROP discount_kind, DROP discount_value, DROP discount_gross');
    }
}
