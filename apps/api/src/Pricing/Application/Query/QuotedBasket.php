<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The quote of a basket, amounts in grosze. The lines are those with an effective price, in the order of the basket;
 * the totals are their sums (`subtotalGross` before the code, `gross` after it). With a code, either `discount` (the
 * code applied) or `rejection` (why not); without one, both are null.
 */
final readonly class QuotedBasket
{
    /**
     * @param list<QuotedBasketLine> $lines
     */
    public function __construct(
        public array $lines,
        public int $subtotalGross,
        public int $discountGross,
        public int $net,
        public int $vat,
        public int $gross,
        public string $currency,
        public ?QuotedDiscount $discount,
        public ?QuotedRejection $rejection,
    ) {
    }
}
