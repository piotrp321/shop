<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * Transitional (removed with GetCurrentProductPrices): the current gross price of a product that has one variant,
 * in grosze.
 */
final readonly class CurrentProductPrice
{
    public function __construct(
        public string $productId,
        public int $gross,
        public string $currency,
    ) {
    }
}
