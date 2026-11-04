<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * What the cart needs to know about a product to add it or to increase its quantity: whether it has a current price
 * and how many units are available. The handler asks Pricing and Inventory and hands it to the cart.
 *
 * The one place of the rule of buying: purchasability() is used by the cart for additions and increases and by the
 * cart view for the state of a line.
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

    /**
     * Whether this quantity can be bought: no current price first, then no available unit, then a quantity above the
     * available units.
     */
    public function purchasability(int $quantity): Purchasability
    {
        return match (true) {
            !$this->hasCurrentPrice => Purchasability::NoPrice,
            0 === $this->available => Purchasability::OutOfStock,
            $quantity > $this->available => Purchasability::InsufficientStock,
            default => Purchasability::Purchasable,
        };
    }
}
