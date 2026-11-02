<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

final readonly class StockListItem
{
    /**
     * @param ListedStock|null $stock null when no change of the stock was ever saved for the product
     */
    public function __construct(
        public string $productId,
        public string $name,
        public ?ListedStock $stock,
    ) {
    }
}
