<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Application\View\AppliedCartDiscount;
use App\Cart\Application\View\RejectedCartDiscount;
use App\Cart\Domain\Money;

/**
 * The quote of the purchasable lines: a line without an effective price is not in `lines`. `gross` is the subtotal
 * less the discount; `discount` is null without a code.
 */
final readonly class CartQuoteResult
{
    /**
     * @param array<string, QuotedCartLine> $lines by variant id
     */
    public function __construct(
        public array $lines,
        public Money $subtotalGross,
        public Money $discountGross,
        public Money $gross,
        public AppliedCartDiscount|RejectedCartDiscount|null $discount,
    ) {
    }
}
