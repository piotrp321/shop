<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\VariantId;

/**
 * The authoritative available units and their level (Inventory, read in the process). They limit additions and
 * increases; they are not a reservation.
 */
interface StockAvailability
{
    /**
     * One read for all the variants; every variant has an answer (none stored: no unit, out of stock).
     *
     * @param list<VariantId> $ids 0 to 4800 distinct ids (the lines of a cart, or the variants of a page of the list)
     *
     * @return list<VariantAvailability>
     */
    public function of(array $ids): array;
}
