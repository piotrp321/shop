<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\CartRef;

/**
 * Empties the cart an order was placed from (Cart::clearAfterOrder()). Dispatched by Ordering in the transaction that
 * locked the cart with LockCartForOrder, so a failure later in that transaction brings the lines back.
 */
final readonly class ClearCartAfterOrder
{
    public function __construct(public CartRef $cart)
    {
    }
}
