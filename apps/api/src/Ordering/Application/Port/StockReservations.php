<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\OrderId;

/**
 * The stock of an order (Inventory), reserved in the transaction of the placement with the order id as the key,
 * released in the transaction that cancels the order and committed in the one that ships it. Lock the order first.
 */
interface StockReservations
{
    /**
     * Reserves every line or none.
     *
     * @param non-empty-list<CartLineToOrder> $lines each variant once
     *
     * @throws InsufficientStockForOrder
     */
    public function reserve(OrderId $orderId, array $lines): void;

    /**
     * Gives the reserved units back; a reservation already released stays as it is. Any failure is thrown as it is
     * (a reservation Inventory does not know or one already committed is a logic error).
     */
    public function release(OrderId $orderId): void;

    /**
     * Takes the reserved units off the shelf (physical and reserved decrease); a reservation already committed stays
     * as it is. Any failure is thrown as it is (a reservation Inventory does not know or one already released is
     * a logic error).
     */
    public function commit(OrderId $orderId): void;
}
