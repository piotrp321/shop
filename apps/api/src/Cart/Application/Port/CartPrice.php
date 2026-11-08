<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\Money;
use App\Cart\Domain\VariantId;
use App\Cart\Domain\VariantPromotion;

/**
 * The current gross price of a variant, as Pricing gives it: the effective price (in a promotion the promotional
 * one), the catalog gross and the promotion (null outside one).
 */
final readonly class CartPrice
{
    public function __construct(
        public VariantId $variantId,
        public Money $gross,
        public Money $regularGross,
        public ?VariantPromotion $promotion,
    ) {
    }
}
