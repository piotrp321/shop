<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * A variant of a product in the stock list, with its stock.
 */
final readonly class StockListVariant
{
    /**
     * @param string           $label        the values of the variant joined by ", ", empty for the default variant
     * @param ListedStock|null $stock        null when the variant has no stock record
     * @param bool             $matchesLevel the stock is at the level the list is filtered by; false when it is not filtered
     */
    public function __construct(
        public string $variantId,
        public string $label,
        public ?ListedStock $stock,
        public bool $matchesLevel = false,
    ) {
    }
}
