<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

/**
 * A line of a cart locked for an order: the variant and its units.
 */
final readonly class CartLineForOrder
{
    public function __construct(
        public string $variantId,
        public int $quantity,
    ) {
    }
}
