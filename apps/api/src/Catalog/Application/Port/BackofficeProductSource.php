<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\ProductCursor;
use App\Catalog\Domain\Category\CategoryPath;

/**
 * Products of the catalog for the price list of the panel, read from the database.
 */
interface BackofficeProductSource
{
    /**
     * At most $limit products ordered by (name, id), the ones after the cursor. No total is counted.
     *
     * @param string|null       $term     a literal part of the name, matched case-insensitively; pattern characters
     *                                    (`%`, `_`, `\`) have no special meaning
     * @param CategoryPath|null $category the path of the category; products of it and of its subcategories, with it
     *                                    as the main or an additional category
     * @param int               $limit    at least 1
     *
     * @return list<BackofficeProduct>
     */
    public function page(?string $term, ?CategoryPath $category, ?ProductCursor $after, int $limit): array;
}
