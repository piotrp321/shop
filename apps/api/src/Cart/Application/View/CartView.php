<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * A cart with everything the store shows, computed by the API. `id` is null for a customer without a cart.
 * `discount` is the code of the view (applied or rejected), null without a code or without a purchasable line.
 */
final readonly class CartView
{
    /**
     * @param list<CartLineView> $lines in the order they were added
     */
    public function __construct(
        public ?string $id,
        public array $lines,
        public CartTotals $totals,
        public bool $hasExcludedLines,
        public AppliedCartDiscount|RejectedCartDiscount|null $discount,
    ) {
    }
}
