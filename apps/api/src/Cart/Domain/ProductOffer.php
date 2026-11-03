<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * What the cart needs to know about a product to add it or to increase its quantity: whether it has a current price
 * and how many units are available. The handler asks Pricing and Inventory and hands it to the cart.
 */
final readonly class ProductOffer
{
    public function __construct(
        public bool $hasCurrentPrice,
        public int $available,
    ) {
        if ($available < 0) {
            throw new \InvalidArgumentException('The available units are never negative.');
        }
    }
}
