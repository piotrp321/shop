<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\ProductId;

/**
 * All products of Catalog in batches by id, for the demo stock.
 */
interface ProductsForInventory
{
    /**
     * @param int $limit 1 to 1000
     *
     * @return list<CatalogProduct> ordered by id, starting after $after; empty after the last product
     */
    public function page(?ProductId $after, int $limit): array;
}
