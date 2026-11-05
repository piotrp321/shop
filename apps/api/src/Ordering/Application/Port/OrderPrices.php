<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\VariantId;

/**
 * The authoritative current unit prices (Pricing), asked once for all the lines of an order.
 */
interface OrderPrices
{
    /**
     * @param non-empty-list<VariantId> $variantIds at most 50, each once
     *
     * @return array<string, PriceCopy> by variant id; a variant without a current price in a currency of Ordering
     *                                  is left out
     */
    public function current(array $variantIds): array;
}
