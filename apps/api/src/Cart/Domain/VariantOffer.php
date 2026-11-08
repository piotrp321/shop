<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * What the list knows about one variant of a product: its current (effective) gross price (null without one), the
 * available units, the level Inventory gives them and the promotion that gives the price (null outside one). Whether
 * it can be bought is the rule of the cart (ProductOffer).
 */
final readonly class VariantOffer
{
    public function __construct(
        public ?Money $gross,
        public int $available,
        public StockLevel $level,
        public ?VariantPromotion $promotion,
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
