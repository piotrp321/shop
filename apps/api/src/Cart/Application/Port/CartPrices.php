<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\VariantId;

/**
 * The authoritative current prices (Pricing). The cart always shows the current price, which is the effective one
 * (the promotional price in an active promotion).
 */
interface CartPrices
{
    /**
     * One read for all the variants; a variant without a current price is left out.
     *
     * @param list<VariantId> $ids                  0 to 4800 distinct ids (the lines of a cart, or the variants of a
     *                                              page of the list)
     * @param bool            $withLowestPriorPrice whether the promotions carry their Omnibus price (only the offers
     *                                              of the store list show it; it costs two more reads)
     *
     * @return list<CartPrice>
     */
    public function current(array $ids, bool $withLowestPriorPrice = false): array;
}
