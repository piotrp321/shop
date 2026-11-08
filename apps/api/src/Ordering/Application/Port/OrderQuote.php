<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderDiscount;

/**
 * The quote of the lines of an order. A line without an effective price in a currency of Ordering is left out. With a
 * code either `discount` (the copy of the code applied, with the id of the code to record its usage) or `rejection`;
 * without one, both are null.
 */
final readonly class OrderQuote
{
    /**
     * @param array<string, QuotedOrderLine> $lines         by variant id
     * @param int                            $subtotalGross the gross of the lines before the code, in grosze
     */
    public function __construct(
        public array $lines,
        public int $subtotalGross,
        public ?OrderDiscount $discount,
        public ?string $discountCodeId,
        public ?QuotedCodeRejection $rejection,
    ) {
    }
}
