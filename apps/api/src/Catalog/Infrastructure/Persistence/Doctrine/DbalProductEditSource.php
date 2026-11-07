<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductEditSource;
use App\Catalog\Domain\Product\ProductId;
use Doctrine\DBAL\Connection;

/**
 * Reads a product for the edit form with DBAL: the row of the product (primary key), its additional categories and its
 * variants (indexes of the product).
 */
final readonly class DbalProductEditSource implements ProductEditSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(ProductId $id): ?array
    {
        $product = $this->connection->fetchAssociative(
            'SELECT id, version, status, first_published_at, name, slug, short_description, primary_category_id, tax_class, images, variant_axes, updated_at
             FROM catalog_product WHERE id = CAST(:id AS uuid)',
            ['id' => $id->toString()],
        );
        if (false === $product) {
            return null;
        }
        \assert(\is_string($product['id']) && \is_int($product['version']) && \is_string($product['status']) && \is_string($product['name']));
        \assert(\is_string($product['slug']) && \is_string($product['short_description']) && \is_string($product['primary_category_id']));
        \assert(\is_string($product['tax_class']) && \is_string($product['updated_at']));
        \assert(null === $product['first_published_at'] || \is_string($product['first_published_at']));

        $additional = $this->connection->fetchFirstColumn(
            'SELECT category_id FROM catalog_product_category WHERE product_id = CAST(:id AS uuid) ORDER BY category_id',
            ['id' => $product['id']],
        );
        $variants = $this->connection->fetchAllAssociative(
            'SELECT id, option_values FROM catalog_product_variant WHERE product_id = CAST(:id AS uuid) ORDER BY position',
            ['id' => $product['id']],
        );

        return [
            'id' => $product['id'],
            'version' => $product['version'],
            'status' => $product['status'],
            'firstPublishedAt' => null === $product['first_published_at'] ? null : new \DateTimeImmutable($product['first_published_at']),
            'name' => $product['name'],
            'slug' => $product['slug'],
            'shortDescription' => $product['short_description'],
            'mainCategoryId' => $product['primary_category_id'],
            'additionalCategoryIds' => array_map(static fn (mixed $categoryId): string => \is_string($categoryId) ? $categoryId : throw new \UnexpectedValueException('Expected a category id.'), $additional),
            'taxClass' => $product['tax_class'],
            'images' => self::images($product['images']),
            'axes' => self::strings($product['variant_axes']),
            'variants' => array_map(static function (array $variant): array {
                \assert(\is_string($variant['id']));

                return ['id' => $variant['id'], 'values' => self::strings($variant['option_values'])];
            }, $variants),
            'updatedAt' => new \DateTimeImmutable($product['updated_at']),
        ];
    }

    /**
     * @return list<array{key: string, alt: string}>
     */
    private static function images(mixed $json): array
    {
        \assert(\is_string($json));
        $images = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($images) && array_is_list($images));

        /** @var list<array{key: string, alt: string}> $images */
        return array_map(static fn (array $image): array => ['key' => $image['key'], 'alt' => $image['alt']], $images);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $json): array
    {
        \assert(\is_string($json));
        $values = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($values) && array_is_list($values));

        /** @var list<string> $values */
        return $values;
    }
}
