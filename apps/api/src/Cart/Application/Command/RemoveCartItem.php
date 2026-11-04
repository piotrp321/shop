<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\CartRef;

final readonly class RemoveCartItem
{
    public function __construct(
        public CartRef $cart,
        public string $variantId,
    ) {
    }
}
