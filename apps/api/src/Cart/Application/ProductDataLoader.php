<?php

declare(strict_types=1);

namespace App\Cart\Application;

use App\Cart\Application\Port\CartPrices;
use App\Cart\Application\Port\ProductCatalog;
use App\Cart\Application\Port\StockAvailability;
use App\Cart\Domain\ProductId;

/**
 * Asks each port once for all the products; without products, no port is asked.
 */
final readonly class ProductDataLoader
{
    public function __construct(
        private ProductCatalog $catalog,
        private CartPrices $prices,
        private StockAvailability $stock,
    ) {
    }

    /**
     * @param list<ProductId> $ids
     */
    public function load(array $ids): ProductData
    {
        $distinct = [];
        foreach ($ids as $id) {
            $distinct[$id->toString()] = $id;
        }
        $distinct = array_values($distinct);
        if ([] === $distinct) {
            return new ProductData([], [], []);
        }

        return new ProductData($this->catalog->summaries($distinct), $this->prices->current($distinct), $this->stock->of($distinct));
    }
}
