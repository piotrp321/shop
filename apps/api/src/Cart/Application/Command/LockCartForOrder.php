<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\CartRef;

/**
 * Locks the cart an order is placed from, until the end of the transaction, and gives its lines. Dispatched by
 * Ordering inside its own transaction (a nested command); Cart knows nothing of orders.
 */
final readonly class LockCartForOrder
{
    public function __construct(public CartRef $cart)
    {
    }
}
