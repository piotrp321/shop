<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Query\ProductIdentity;
use App\Catalog\Domain\Product\ProductId;

/**
 * What Inventory needs to know about products.
 */
interface ProductsForInventorySource
{
    public function exists(ProductId $id): bool;

    /**
     * @param int $limit at least 1
     *
     * @return list<ProductIdentity> ordered by id, starting after $after
     */
    public function page(?ProductId $after, int $limit): array;
}
