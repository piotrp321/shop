<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The authoritative current (effective) prices of 1 to 4800 variants (48 products of 100 variants). Read from the
 * Pricing tables in the process, never cached: one query for the prices and one for the active promotions, and with
 * `withLowestPriorPrice`, when a variant is in a promotion, one for its price history and one for its earlier
 * promotions (the Omnibus price). The cart, the order and the search index do not ask for the Omnibus price.
 */
final readonly class GetCurrentPrices
{
    public const int MAX_VARIANTS = 4800;

    /**
     * @param list<string> $variantIds variant UUIDs
     */
    public function __construct(
        public array $variantIds,
        public bool $withLowestPriorPrice = false,
    ) {
    }
}
