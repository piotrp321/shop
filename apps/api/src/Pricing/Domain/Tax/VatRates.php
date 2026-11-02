<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Tax;

/**
 * The VAT rate of each tax class.
 */
interface VatRates
{
    /**
     * @throws TaxClassWithoutRate
     */
    public function rateFor(TaxClass $taxClass): VatRate;
}
