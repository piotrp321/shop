<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Catalog products for the tests whose participants ask Catalog in processes of their own (ReceiveStock and
 * CorrectStock refuse a variant Catalog does not have), written as rows on a connection that commits. Every product
 * has one default variant whose id is not the id of the product.
 */
final class CatalogFixture
{
    /**
     * @return string the product id
     */
    public static function product(Connection $connection): string
    {
        $categoryId = (string) Uuid::v7();
        $productId = (string) Uuid::v7();
        $variantId = (string) Uuid::v7();
        $connection->executeStatement('INSERT INTO catalog_category (id, name, path, parent_id) VALUES (?, ?, ?, NULL)', [$categoryId, 'Concurrency', '/'.$categoryId.'/']);
        $connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, variant_axes, primary_category_id, version) VALUES (?, ?, 'Concurrency', '', 'standard', '[]', '[]', ?, 1)",
            [$productId, 'concurrency-'.$productId, $categoryId],
        );
        $connection->executeStatement(
            "INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, 0, '[]')",
            [$variantId, $productId],
        );

        return $productId;
    }

    /**
     * @return string the id of the default variant of the product
     */
    public static function defaultVariant(Connection $connection, string $productId): string
    {
        $variantId = $connection->fetchOne('SELECT id FROM catalog_product_variant WHERE product_id = ?', [$productId]);
        \assert(\is_string($variantId));

        return $variantId;
    }

    /**
     * Deletes the products (their variants go with them) and their categories.
     *
     * @param list<string> $productIds
     */
    public static function delete(Connection $connection, array $productIds): void
    {
        if ([] === $productIds) {
            return;
        }
        $categoryIds = $connection->fetchFirstColumn('SELECT primary_category_id FROM catalog_product WHERE id IN (?)', [$productIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM catalog_product WHERE id IN (?)', [$productIds], [ArrayParameterType::STRING]);
        $connection->executeStatement('DELETE FROM catalog_category WHERE id IN (?)', [$categoryIds], [ArrayParameterType::STRING]);
    }
}
