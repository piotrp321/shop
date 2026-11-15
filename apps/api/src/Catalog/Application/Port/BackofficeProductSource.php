<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Product\ProductStatus;
use App\Shared\Domain\BoundedCount;

/**
 * Products of the catalog for the price list of the panel, read from the database.
 */
interface BackofficeProductSource
{
    /**
     * At most $limit products in the order of the sort (by name or by the moment of the last change, then by id, in
     * the direction of the sort), the ones after the cursor. No total is counted.
     *
     * @param string|null        $term          a literal part of the name, matched case-insensitively; pattern characters
     *                                          (`%`, `_`, `\`) have no special meaning
     * @param CategoryPath|null  $category      the path of the category; products of it and of its subcategories, with it
     *                                          as the main or an additional category
     * @param ProductStatus|null $status        only products in the status; null for both
     * @param ProductCursor|null $after         of the same sort
     * @param int                $limit         at least 1
     * @param bool               $withoutImages only the products that have no image
     *
     * @return list<BackofficeProduct>
     */
    public function page(?string $term, ?CategoryPath $category, ?ProductStatus $status, ?ProductCursor $after, ProductSort $sort, int $limit, bool $withoutImages = false): array;

    /**
     * How many products match the filters of {@see page()}, whatever the cursor: counted up to 10 000 (at most 10 001
     * rows are read), never the whole table.
     *
     * @param string|null        $term          as in page()
     * @param CategoryPath|null  $category      as in page()
     * @param ProductStatus|null $status        as in page()
     * @param bool               $withoutImages as in page()
     */
    public function count(?string $term, ?CategoryPath $category, ?ProductStatus $status, bool $withoutImages = false): BoundedCount;

    /**
     * What the panel's product list shows besides the name, of the given products.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{slug: string, status: string, mainCategoryId: string, mainCategoryName: string, variantCount: int, imageKey: string|null, imageAlt: string|null, updatedAt: \DateTimeImmutable}> by product id; products that do not exist are missing
     */
    public function summaries(array $ids): array;
}
