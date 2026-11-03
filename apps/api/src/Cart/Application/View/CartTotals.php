<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * `gross` sums the purchasable lines only; `itemCount` counts the units of every line.
 */
final readonly class CartTotals
{
    public function __construct(
        public int $gross,
        public string $currency,
        public int $itemCount,
    ) {
    }
}
