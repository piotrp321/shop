<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\ProductId;

/**
 * The authoritative available units (Inventory, read in the process). It limits additions and increases; it is not
 * a reservation.
 */
interface StockAvailability
{
    /**
     * One read for all the products.
     *
     * @param list<ProductId> $ids 0 to 100 distinct ids
     *
     * @return list<ProductAvailability>
     */
    public function of(array $ids): array;
}
