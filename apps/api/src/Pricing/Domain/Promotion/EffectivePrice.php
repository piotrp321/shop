<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\VatRates;

/**
 * The price a variant is sold at, at a moment: its current catalog price, and in a promotion active then its
 * promotional price. The promotional gross comes from PromotionalPrice, its net and VAT from PriceBreakdown at the
 * rate of the catalog price. A variant without a current catalog price (see VariantPrice::isCurrent) has no
 * effective price; a promotion does not change that.
 */
final readonly class EffectivePrice
{
    private function __construct(
        private PriceBreakdown $breakdown,
        private GrossPrice $regularGross,
    ) {
    }

    /**
     * @param PercentOff|null $activePromotion the discount of the promotion of the variant active at the moment
     */
    public static function of(VariantPrice $catalog, VatRates $rates, ?PercentOff $activePromotion): ?self
    {
        if (!$catalog->isCurrent($rates)) {
            return null;
        }
        $regular = $catalog->breakdown();
        if (null === $activePromotion) {
            return new self($regular, $regular->gross());
        }

        return new self(
            PriceBreakdown::fromGross(PromotionalPrice::apply($regular->gross(), $activePromotion), $regular->rate()),
            $regular->gross(),
        );
    }

    public function breakdown(): PriceBreakdown
    {
        return $this->breakdown;
    }

    /**
     * The catalog gross.
     */
    public function regularGross(): GrossPrice
    {
        return $this->regularGross;
    }
}
