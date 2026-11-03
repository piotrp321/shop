<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\ProductSummarySource;
use App\Catalog\Domain\Product\ProductId;
use Doctrine\DBAL\Connection;

final readonly class DbalProductSummarySource implements ProductSummarySource
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * One statement, `id = ANY(:ids)` on the primary key. The main image is the first of the images.
     */
    public function findMany(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // A PostgreSQL array literal of validated UUIDs (hex digits and dashes only), bound as one parameter.
        $literal = '{'.implode(',', array_map(static fn (ProductId $id): string => $id->toString(), $ids)).'}';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, images->0 AS main_image FROM catalog_product WHERE id = ANY(CAST(:ids AS uuid[]))',
            ['ids' => $literal],
        );

        return array_map(static function (array $row): array {
            \assert(\is_string($row['id']) && \is_string($row['name']));
            $mainImage = null;
            if (\is_string($row['main_image'])) {
                $image = json_decode($row['main_image'], true, flags: \JSON_THROW_ON_ERROR);
                \assert(\is_array($image) && \is_string($image['key']) && \is_string($image['alt']));
                $mainImage = ['key' => $image['key'], 'alt' => $image['alt']];
            }

            return ['id' => $row['id'], 'name' => $row['name'], 'mainImage' => $mainImage];
        }, $rows);
    }
}
