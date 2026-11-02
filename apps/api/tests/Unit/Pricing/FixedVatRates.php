<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\TaxClassWithoutRate;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Domain\Tax\VatRates;

/**
 * A rate table given by the test, e.g. to play a rate changed in the code after the price was stored.
 */
final readonly class FixedVatRates implements VatRates
{
    /**
     * @param array<string, int> $rates tax class code => basis points
     */
    public function __construct(private array $rates)
    {
    }

    public function rateFor(TaxClass $taxClass): VatRate
    {
        return VatRate::fromBasisPoints($this->rates[$taxClass->toString()] ?? throw new TaxClassWithoutRate());
    }
}
