<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\ProductId;

/**
 * The authoritative current prices (Pricing). The cart always shows the current price.
 */
interface CartPrices
{
    /**
     * One read for all the products; a product without a current price is left out.
     *
     * @param list<ProductId> $ids 0 to 100 distinct ids
     *
     * @return list<CartPrice>
     */
    public function current(array $ids): array;
}
