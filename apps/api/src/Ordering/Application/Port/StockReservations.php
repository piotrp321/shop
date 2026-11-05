<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\OrderId;

/**
 * The stock of an order (Inventory), reserved in the transaction of the placement with the order id as the key.
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
}
