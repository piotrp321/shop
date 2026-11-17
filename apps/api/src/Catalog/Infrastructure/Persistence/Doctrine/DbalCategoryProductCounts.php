<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Application\Port\CategoryProductCounts;
use App\Catalog\Domain\Category\CategoryId;
use App\Shared\Domain\BoundedCount;
use Doctrine\DBAL\Connection;

/**
 * Counts at most limit + 1 rows per category: the products with the category as their main one (index on
 * primary_category_id) and those with it as an additional one (index on catalog_product_category.category_id), drafts
 * too. A product never has its main category among the additional ones (ProductCategories), so UNION ALL counts every
 * product once, and the scan stops at the limit.
 */
final readonly class DbalCategoryProductCounts implements CategoryProductCounts
{
    private const string COUNT = '(SELECT count(*) FROM ('
        .'SELECT 1 FROM catalog_product p WHERE p.primary_category_id = %1$s '
        .'UNION ALL SELECT 1 FROM catalog_product_category pc WHERE pc.category_id = %1$s '
        .'LIMIT %2$s) t)';

    public function __construct(private Connection $connection)
    {
    }

    public function count(CategoryId $id, int $limit): BoundedCount
    {
        $rows = $this->connection->fetchOne(
            'SELECT '.\sprintf(self::COUNT, 'CAST(:id AS uuid)', ':rows'),
            ['id' => $id->toString(), 'rows' => $limit + 1],
        );

        \assert(\is_int($rows));

        return BoundedCount::ofRowsRead($rows, $limit);
    }

    public function countAll(int $limit): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT c.id, '.\sprintf(self::COUNT, 'c.id', ':rows').' AS products FROM catalog_category c',
            ['rows' => $limit + 1],
        );

        $counts = [];
        foreach ($rows as $id => $count) {
            \assert(\is_string($id) && \is_int($count));
            if ($count > 0) {
                $counts[$id] = BoundedCount::ofRowsRead($count, $limit);
            }
        }

        return $counts;
    }
}
