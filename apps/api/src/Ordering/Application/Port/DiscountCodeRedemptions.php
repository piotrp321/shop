<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\DiscountCodeRejected;
use App\Ordering\Domain\OrderId;

/**
 * The usages of discount codes (Pricing): recorded in the transaction of the placement, released in the one that
 * cancels an unpaid order (decisions D4, D7, A4).
 */
interface DiscountCodeRedemptions
{
    /**
     * Records the usage of the code by the order; the code is checked once more (locked when it has a limit).
     *
     * @param int $subtotalGross the gross of the order before the code
     *
     * @throws DiscountCodeRejected
     */
    public function redeem(string $codeId, OrderId $orderId, int $subtotalGross): void;

    /**
     * Releases the usage of the order; an order without one, or with one released already, changes nothing.
     */
    public function release(OrderId $orderId): void;
}
