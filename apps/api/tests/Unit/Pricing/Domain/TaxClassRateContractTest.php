<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use PHPUnit\Framework\TestCase;

/**
 * Contract between the modules: every tax class a Catalog product can have has a rate in Pricing, and Pricing has
 * no rate for a class Catalog does not know. A new class needs both (spec: "Klasa podatkowa produktu").
 */
final class TaxClassRateContractTest extends TestCase
{
    public function testEveryCatalogTaxClassHasAPolishRate(): void
    {
        $rates = new PolishVatRates();

        foreach (TaxClassCode::cases() as $case) {
            $rate = $rates->rateFor(TaxClass::fromString($case->value));
            self::assertGreaterThanOrEqual(0, $rate->basisPoints(), $case->value);
        }
    }

    public function testEveryPolishRateBelongsToACatalogTaxClass(): void
    {
        $catalogCodes = array_map(static fn (TaxClassCode $case): string => $case->value, TaxClassCode::cases());
        $pricedCodes = array_keys(new PolishVatRates()->all());
        sort($catalogCodes);
        sort($pricedCodes);

        self::assertSame($catalogCodes, $pricedCodes);
    }
}
