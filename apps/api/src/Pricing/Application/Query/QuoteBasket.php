<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The one pricing of a basket, for the cart view and the placement of an order (decision D9): the effective prices of
 * its lines, read like GetCurrentPrices without the Omnibus price, and the discount of a code allocated over them.
 * A blank code is no code; a text that is no code is an unknown code (rejected as invalid), not an error.
 */
final readonly class QuoteBasket
{
    public const int MAX_LINES = 50;
    /** Longer than any code; the HTTP boundaries refuse a longer text first. */
    public const int MAX_CODE_LENGTH = 64;

    /**
     * @param list<QuoteBasketLine> $lines 1 to 50 lines of distinct variants, in the order of the basket
     */
    public function __construct(
        public array $lines,
        public ?string $discountCode = null,
    ) {
    }
}
