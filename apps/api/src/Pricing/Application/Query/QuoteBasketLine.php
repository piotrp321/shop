<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

final readonly class QuoteBasketLine
{
    /**
     * @param string $variantId a variant UUID
     * @param int    $quantity  1 to 99
     */
    public function __construct(
        public string $variantId,
        public int $quantity,
    ) {
    }
}
