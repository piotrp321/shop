<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Tax\VatRate;

/**
 * Gross, net and VAT of the value of a line of a basket at the VAT rate of its price. Made only by
 * {@see PriceBreakdown::ofLineGross()}, so net + VAT is always the gross.
 */
final readonly class LineBreakdown
{
    /**
     * @internal use PriceBreakdown::ofLineGross()
     */
    public function __construct(
        private Money $gross,
        private Money $net,
        private Money $vat,
        private VatRate $rate,
    ) {
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function vat(): Money
    {
        return $this->vat;
    }

    public function rate(): VatRate
    {
        return $this->rate;
    }
}
