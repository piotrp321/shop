<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * A product of the stock list with its variants.
 */
final readonly class StockListItem
{
    /**
     * @param list<StockListVariant> $variants in presentation order
     */
    public function __construct(
        public string $productId,
        public string $name,
        public array $variants,
    ) {
    }
}
