<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductDetailsSource;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\Slug;
use Doctrine\DBAL\Connection;

/**
 * Reads the product page with DBAL, three statements on indexed columns: the product with the path of its main
 * category (unique slug), the names along the path (primary keys), the variants (index of the product).
 */
final readonly class DbalProductDetailsSource implements ProductDetailsSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function bySlug(Slug $slug): ?array
    {
        $product = $this->connection->fetchAssociative(
            'SELECT p.id, p.slug, p.name, p.short_description, p.images, p.variant_axes, main.path
             FROM catalog_product p
             JOIN catalog_category main ON main.id = p.primary_category_id
             WHERE p.slug = :slug',
            ['slug' => $slug->value()],
        );
        if (false === $product) {
            return null;
        }
        \assert(\is_string($product['id']) && \is_string($product['slug']) && \is_string($product['name']));
        \assert(\is_string($product['short_description']) && \is_string($product['path']));

        $variants = $this->connection->fetchAllAssociative(
            'SELECT id, option_values FROM catalog_product_variant WHERE product_id = CAST(:id AS uuid) ORDER BY position',
            ['id' => $product['id']],
        );

        return [
            'id' => $product['id'],
            'slug' => $product['slug'],
            'name' => $product['name'],
            'shortDescription' => $product['short_description'],
            'breadcrumb' => $this->breadcrumb(CategoryPath::fromString($product['path'])),
            'images' => self::images($product['images']),
            'axes' => self::strings($product['variant_axes']),
            'variants' => array_map(static function (array $variant): array {
                \assert(\is_string($variant['id']));

                return ['id' => $variant['id'], 'values' => self::strings($variant['option_values'])];
            }, $variants),
        ];
    }

    /**
     * @return list<array{id: string, name: string}> from the root to the category of the path
     */
    private function breadcrumb(CategoryPath $path): array
    {
        $ids = array_map(static fn (CategoryId $id): string => $id->toString(), $path->ancestorIds());
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name
             FROM unnest(CAST(:ids AS uuid[])) WITH ORDINALITY AS t(id, position)
             JOIN catalog_category c ON c.id = t.id
             ORDER BY t.position',
            ['ids' => '{'.implode(',', $ids).'}'],
        );

        return array_map(static function (array $row): array {
            \assert(\is_string($row['id']) && \is_string($row['name']));

            return ['id' => $row['id'], 'name' => $row['name']];
        }, $rows);
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
