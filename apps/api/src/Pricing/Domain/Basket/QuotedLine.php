<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Basket;

use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\LineBreakdown;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;

/**
 * A line of a quote: the effective unit price, the value before the code (unit gross × units), its share of the
 * discount of the code and its gross, net and VAT after it (net and VAT from the line gross, D8).
 */
final readonly class QuotedLine
{
    public function __construct(
        private BasketLine $line,
        private BasketPrice $price,
        private Money $grossBeforeDiscount,
        private Money $discount,
        private LineBreakdown $amounts,
    ) {
    }

    public function variantId(): VariantId
    {
        return $this->line->variantId();
    }

    public function quantity(): int
    {
        return $this->line->quantity();
    }

    /**
     * The effective unit price.
     */
    public function unit(): PriceBreakdown
    {
        return $this->price->price()->breakdown();
    }

    public function taxClass(): TaxClass
    {
        return $this->price->taxClass();
    }

    /**
     * The catalog unit gross.
     */
    public function regularUnitGross(): GrossPrice
    {
        return $this->price->price()->regularGross();
    }

    public function promotion(): ?BasketPromotion
    {
        return $this->price->promotion();
    }

    public function grossBeforeDiscount(): Money
    {
        return $this->grossBeforeDiscount;
    }

    /**
     * The share of the discount of the code, 0 without one.
     */
    public function discount(): Money
    {
        return $this->discount;
    }

    /**
     * Gross (before discount less the discount), net and VAT of the line.
     */
    public function amounts(): LineBreakdown
    {
        return $this->amounts;
    }
}
