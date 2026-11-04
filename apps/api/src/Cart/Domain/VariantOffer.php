<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * What the list knows about one variant of a product: its current gross price (null without one), the available
 * units and the level Inventory gives them. Whether it can be bought is the rule of the cart (ProductOffer).
 */
final readonly class VariantOffer
{
    public function __construct(
        public ?Money $gross,
        public int $available,
        public StockLevel $level,
    ) {
    }

    /**
     * Whether one unit can be bought: the same rule as an addition to the cart, ProductOffer::purchasability().
     */
    public function isPurchasable(): bool
    {
        return Purchasability::Purchasable === new ProductOffer(null !== $this->gross, $this->available)->purchasability(1);
    }
}
