<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The totals of the purchasable lines, from Pricing's quote: `subtotalGross` before the code, `discountGross` of the
 * code, `gross` to pay. All 0 without a purchasable line. `itemCount` counts the units of every line.
 */
final readonly class CartTotals
{
    public function __construct(
        public int $subtotalGross,
        public int $discountGross,
        public int $gross,
        public string $currency,
        public int $itemCount,
    ) {
    }
}
