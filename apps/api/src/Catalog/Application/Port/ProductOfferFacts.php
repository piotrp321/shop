<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductFacts;

/**
 * Prices and availability of products for the search index, as the offer of the product list computes them. Catalog
 * never decides what can be bought or what the "from" price is: the adapter asks the module that owns the offer.
 */
interface ProductOfferFacts
{
    /**
     * @param list<string> $productIds product UUIDs, any number
     *
     * @return array<string, ProductFacts> by product id; a product missing from the result has no price and nothing
     *                                     to buy, a variant missing from its facts has no price and cannot be bought
     *
     * @throws OfferFactsUnavailable when the offers could not be read
     */
    public function of(array $productIds): array;
}
