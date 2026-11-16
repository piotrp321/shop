<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Category\CategoryId;
use App\Shared\Domain\BoundedCount;

/**
 * The products directly in a category: as their main or an additional category, drafts too (not those of its
 * subcategories). Counted up to a limit, so the cost does not grow with the catalog.
 */
interface CategoryProductCounts
{
    public function count(CategoryId $id, int $limit): BoundedCount;

    /**
     * @return array<string, BoundedCount> category id => its products, for every category with at least one
     */
    public function countAll(int $limit): array;
}
