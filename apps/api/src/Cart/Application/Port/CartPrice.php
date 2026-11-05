<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\Money;
use App\Cart\Domain\VariantId;

/**
 * The current gross price of a variant, as Pricing gives it.
 */
final readonly class CartPrice
{
    public function __construct(
        public VariantId $variantId,
        public Money $gross,
    ) {
    }
}
