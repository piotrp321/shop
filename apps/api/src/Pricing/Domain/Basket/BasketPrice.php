<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Basket;

use App\Pricing\Domain\Promotion\EffectivePrice;
use App\Pricing\Domain\Tax\TaxClass;

/**
 * The effective price of the variant of a line (the catalog price, or the promotional one in an active promotion),
 * the tax class of its catalog price and the promotion that gives it.
 */
final readonly class BasketPrice
{
    public function __construct(
        private EffectivePrice $price,
        private TaxClass $taxClass,
        private ?BasketPromotion $promotion,
    ) {
    }

    public function price(): EffectivePrice
    {
        return $this->price;
    }

    public function taxClass(): TaxClass
    {
        return $this->taxClass;
    }

    public function promotion(): ?BasketPromotion
    {
        return $this->promotion;
    }
}
