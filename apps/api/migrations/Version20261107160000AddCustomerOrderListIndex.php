<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107160000AddCustomerOrderListIndex extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the orders of customers for their history in the store: newest first per customer.';
    }

    public function up(Schema $schema): void
    {
        // The keyset order of the history (placed_at DESC, id DESC) of one customer; guest orders are not in it.
        $this->addSql("CREATE INDEX ordering_order_customer_placed_at_id_idx ON ordering_order (customer_id, placed_at DESC, id DESC) WHERE owner_type = 'customer'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ordering_order_customer_placed_at_id_idx');
    }
}
