<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Product\ProductId;

/**
 * What Inventory needs to know about products.
 */
interface ProductsForInventorySource
{
    public function exists(ProductId $id): bool;
}
