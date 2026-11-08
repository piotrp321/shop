<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Domain;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\EffectivePrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use PHPUnit\Framework\TestCase;

final class EffectivePriceTest extends TestCase
{
    public function testWithoutAnActivePromotionItIsTheCatalogPrice(): void
    {
        $catalog = $this->catalog(12_300, 'standard', 2300);

        $effective = EffectivePrice::of($catalog, new PolishVatRates(), null);

        self::assertNotNull($effective);
        self::assertTrue($effective->breakdown()->equals($catalog->breakdown()));
        self::assertSame(12_300, $effective->regularGross()->amount());
    }

    public function testInAnActivePromotionItIsThePromotionalGrossWithNetAndVatAtTheRateOfTheCatalogPrice(): void
    {
        $catalog = $this->catalog(5_490, 'printed_book', 500);

        $effective = EffectivePrice::of($catalog, new PolishVatRates(), PercentOff::of(20));

        self::assertNotNull($effective);
        // 5490 − floor(5490 × 20 / 100) = 4392; net 4392 / 1.05 = 4182.86 → 4183, VAT 209.
        self::assertTrue($effective->breakdown()->equals(PriceBreakdown::fromGross(GrossPrice::pln(4_392), VatRate::fromBasisPoints(500))));
        self::assertSame([4_183, 209], [$effective->breakdown()->net()->amount(), $effective->breakdown()->vat()->amount()]);
        self::assertSame(5_490, $effective->regularGross()->amount());
    }

    public function testACatalogPriceAtARateThatIsNoLongerTheRateOfItsClassHasNoEffectivePriceAlsoInAPromotion(): void
    {
        // Stored at 8%, while the standard class is at 23% today.
        $outdated = $this->catalog(10_800, 'standard', 800);
        $withoutRate = $this->catalog(10_800, 'unknown_class', 2300);

        self::assertNull(EffectivePrice::of($outdated, new PolishVatRates(), null));
        self::assertNull(EffectivePrice::of($outdated, new PolishVatRates(), PercentOff::of(20)));
        self::assertNull(EffectivePrice::of($withoutRate, new PolishVatRates(), PercentOff::of(20)));
    }

    private function catalog(int $gross, string $taxClass, int $basisPoints): VariantPrice
    {
        return VariantPrice::reconstitute(
            VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60'),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            1,
            new \DateTimeImmutable(),
        );
    }
}
