<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Cart\Application\CartRef;

final readonly class GetCart
{
    public function __construct(public CartRef $cart)
    {
    }
}
