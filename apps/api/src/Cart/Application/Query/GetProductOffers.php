<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

/**
 * The offers of 1 to 48 products (a page of the product list): their variants with prices, promotions and levels, the
 * "from" price, its promotion and the level of each product, computed from its purchasable variants. With
 * `withLowestPriorPrice` the promotions carry their Omnibus price (the offers of the store); the search index does
 * not need it.
 */
final readonly class GetProductOffers
{
    public const int MAX_PRODUCTS = 48;

    /**
     * @param list<string> $productIds product UUIDs
     */
    public function __construct(
        public array $productIds,
        public bool $withLowestPriorPrice,
    ) {
    }
}
