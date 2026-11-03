<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Cart\Application\CartRef;

/**
 * The number of units in a cart, for the counter in the header of the store.
 */
final readonly class GetCartSummary
{
    public function __construct(public CartRef $cart)
    {
    }
}
