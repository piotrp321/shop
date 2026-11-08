<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * A line of an order. `grossBeforeDiscount` is the unit gross times the quantity, `discountGross` the line's share of
 * the discount of the code (0 without one) and `lineGross` the value after it. The promotion is shown only by the
 * panel.
 */
final readonly class OrderLineView
{
    /**
     * @param string $variantLabel empty for the default variant
     */
    public function __construct(
        public string $variantId,
        public string $productName,
        public string $variantLabel,
        public int $quantity,
        public int $unitGross,
        public int $grossBeforeDiscount,
        public int $discountGross,
        public int $lineGross,
        public ?OrderLinePromotionView $promotion,
    ) {
    }
}
