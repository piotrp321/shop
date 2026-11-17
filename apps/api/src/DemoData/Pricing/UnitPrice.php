<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Tax\VatRate;

/**
 * The price of one unit of a variant at an instant: the catalog price in force, the price after the promotion running
 * then (the same without one), the VAT rate of the catalog price and that promotion.
 */
final readonly class UnitPrice
{
    public function __construct(
        public GrossPrice $regular,
        public GrossPrice $effective,
        public VatRate $rate,
        public ?PromotionSpec $promotion,
    ) {
    }
}
