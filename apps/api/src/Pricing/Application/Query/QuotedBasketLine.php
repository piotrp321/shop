<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * A line of a quote: the effective unit price, the catalog unit gross, the promotion that gives the price, the value
 * before the code (unit gross × units), its share of the discount and its net, VAT and gross after it.
 */
final readonly class QuotedBasketLine
{
    public function __construct(
        public string $variantId,
        public int $quantity,
        public QuotedUnitPrice $unit,
        public int $regularUnitGross,
        public ?QuotedLinePromotion $promotion,
        public int $grossBeforeDiscount,
        public int $discountGross,
        public int $net,
        public int $vat,
        public int $gross,
    ) {
    }
}
