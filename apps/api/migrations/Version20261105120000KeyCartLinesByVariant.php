<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105120000KeyCartLinesByVariant extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key cart lines by product variant: cart_line.product_id becomes variant_id, unique per cart; every value and id stays.';
    }

    public function up(Schema $schema): void
    {
        // The Catalog migration gave every product a default variant with the id of the product, so the stored
        // product ids are the ids of those default variants: only names change, and existing carts keep working.
        $this->addSql('ALTER TABLE cart_line RENAME COLUMN product_id TO variant_id');
        $this->addSql('ALTER INDEX cart_line_cart_product_key RENAME TO cart_line_cart_variant_key');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX cart_line_cart_variant_key RENAME TO cart_line_cart_product_key');
        $this->addSql('ALTER TABLE cart_line RENAME COLUMN variant_id TO product_id');
    }
}
