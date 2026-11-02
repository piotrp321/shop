<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Tax;

/**
 * VAT rates in Poland, one per tax class.
 *
 * The rates are EXAMPLES for the demo data. They have not been verified by an accountant and must be verified
 * before anything is sold. A new tax class needs a case in Catalog's TaxClassCode and a rate here (a contract test
 * checks both). Changing a rate: change it here, deploy, then run `pricing:reconcile-tax --apply`.
 */
final readonly class PolishVatRates implements VatRates
{
    /** Tax class code => rate in basis points. */
    private const array RATES = [
        'standard' => 2300,
        'printed_book' => 500,
        'printed_map' => 500,
        'freeze_dried_meal' => 500,
        'energy_bar' => 500,
        'energy_gel' => 800,
        'protein_powder' => 800,
        'electrolyte_tablet' => 2300,
    ];

    public function rateFor(TaxClass $taxClass): VatRate
    {
        $basisPoints = self::RATES[$taxClass->toString()] ?? throw new TaxClassWithoutRate();

        return VatRate::fromBasisPoints($basisPoints);
    }

    /**
     * @return array<string, VatRate> tax class code => rate
     */
    public function all(): array
    {
        return array_map(VatRate::fromBasisPoints(...), self::RATES);
    }
}
