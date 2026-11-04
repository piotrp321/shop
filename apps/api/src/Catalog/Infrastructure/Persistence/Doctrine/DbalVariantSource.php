<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\VariantSource;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\VariantId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads variants straight from the Catalog tables with DBAL, one statement per call, on the primary keys.
 */
final readonly class DbalVariantSource implements VariantSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function summaries(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.id, v.product_id, p.name, v.option_values, p.images->0 AS main_image
             FROM catalog_product_variant v
             JOIN catalog_product p ON p.id = v.product_id
             WHERE v.id = ANY(CAST(:ids AS uuid[]))',
            ['ids' => self::uuidArray(array_map(static fn (VariantId $id): string => $id->toString(), $ids))],
        );

        return array_map(static function (array $row): array {
            \assert(\is_string($row['id']) && \is_string($row['product_id']) && \is_string($row['name']));
            $mainImage = null;
            if (\is_string($row['main_image'])) {
                $image = json_decode($row['main_image'], true, flags: \JSON_THROW_ON_ERROR);
                \assert(\is_array($image) && \is_string($image['key']) && \is_string($image['alt']));
                $mainImage = ['key' => $image['key'], 'alt' => $image['alt']];
            }

            return [
                'id' => $row['id'],
                'productId' => $row['product_id'],
                'name' => $row['name'],
                'values' => self::values($row['option_values']),
                'mainImage' => $mainImage,
            ];
        }, $rows);
    }

    public function find(VariantId $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT v.id, v.product_id, p.tax_class
             FROM catalog_product_variant v
             JOIN catalog_product p ON p.id = v.product_id
             WHERE v.id = CAST(:id AS uuid)',
            ['id' => $id->toString()],
        );
        if (false === $row) {
            return null;
        }
        \assert(\is_string($row['id']) && \is_string($row['product_id']) && \is_string($row['tax_class']));

        return ['id' => $row['id'], 'productId' => $row['product_id'], 'taxClass' => $row['tax_class']];
    }

    public function ofProducts(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id AS product_id, p.variant_axes, v.id AS variant_id, v.option_values
             FROM catalog_product p
             JOIN catalog_product_variant v ON v.product_id = p.id
             WHERE p.id = ANY(CAST(:ids AS uuid[]))
             ORDER BY p.id, v.position',
            ['ids' => self::uuidArray(array_map(static fn (ProductId $id): string => $id->toString(), $ids))],
        );

        $products = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['product_id']) && \is_string($row['variant_id']));
            $products[$row['product_id']] ??= [
                'productId' => $row['product_id'],
                'axes' => self::values($row['variant_axes']),
                'variants' => [],
            ];
            $products[$row['product_id']]['variants'][] = ['id' => $row['variant_id'], 'values' => self::values($row['option_values'])];
        }

        return array_values($products);
    }

    public function page(?VariantId $after, int $limit): array
    {
        // Two statements rather than "IS NULL OR", so the cursor stays an index condition of the primary key.
        $select = 'SELECT v.id, p.slug, v.option_values, p.tax_class
                   FROM catalog_product_variant v
                   JOIN catalog_product p ON p.id = v.product_id';
        $rows = null === $after
            ? $this->connection->fetchAllAssociative(
                $select.' ORDER BY v.id LIMIT :limit',
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            )
            : $this->connection->fetchAllAssociative(
                $select.' WHERE v.id > CAST(:after AS uuid) ORDER BY v.id LIMIT :limit',
                ['after' => $after->toString(), 'limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );

        return array_map(static function (array $row): array {
            \assert(\is_string($row['id']) && \is_string($row['slug']) && \is_string($row['tax_class']));

            return [
                'id' => $row['id'],
                'productSlug' => $row['slug'],
                'values' => self::values($row['option_values']),
                'taxClass' => $row['tax_class'],
            ];
        }, $rows);
    }

    /**
     * A PostgreSQL array literal of validated UUIDs (hex digits and dashes only), bound as one parameter.
     *
     * @param list<string> $ids
     */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /**
     * @return list<string>
     */
    private static function values(mixed $json): array
    {
        \assert(\is_string($json));
        $values = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($values) && array_is_list($values));

        /** @var list<string> $values */
        return $values;
    }
}
