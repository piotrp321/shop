<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * Transitional (removed with GetProductStockLevels): the available units (physical − reserved) and the availability
 * level (out_of_stock, low_stock, in_stock) of a product.
 */
final readonly class ProductStockLevel
{
    public function __construct(
        public string $productId,
        public int $available,
        public string $level,
    ) {
    }
}
