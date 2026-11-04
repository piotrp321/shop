<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * The stock of a variant: physical, reserved, available (physical − reserved) and its availability level
 * (out_of_stock, low_stock, in_stock). A variant without a stored stock has zeros and no version.
 */
final readonly class StockLevel
{
    public function __construct(
        public string $variantId,
        public int $physical,
        public int $reserved,
        public int $available,
        public string $level,
        public ?int $version,
    ) {
    }
}
