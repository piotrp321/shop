<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

final readonly class ReserveStockLine
{
    /**
     * @param string $productId the product UUID
     * @param int    $quantity  1 to 1000 units
     */
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {
    }
}
