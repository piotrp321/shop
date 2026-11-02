<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

final readonly class StockListItem
{
    /**
     * @param ListedStock|null $stock null when the product has no stock record
     */
    public function __construct(
        public string $productId,
        public string $name,
        public ?ListedStock $stock,
    ) {
    }
}
