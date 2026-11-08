<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The offer of one variant: its label (empty for the default variant), its current (effective) gross price and its
 * catalog gross (in grosze, both null without a current price), its level, whether one unit can be bought and the
 * promotion that gives the price (null outside one). Never a number of units.
 */
final readonly class VariantOfferView
{
    public function __construct(
        public string $variantId,
        public string $label,
        public ?int $gross,
        public string $level,
        public bool $purchasable,
        public ?int $regularGross,
        public ?VariantPromotionView $promotion,
    ) {
    }
}
