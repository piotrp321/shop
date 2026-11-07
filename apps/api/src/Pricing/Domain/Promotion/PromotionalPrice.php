<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\GrossPrice;

/**
 * The gross price of a variant in a promotion: the catalog gross less the percent of it, the discount rounded down
 * to a grosz, in integers. The result is at least 1 grosz (the discount of 1 grosz is 0), so it is a GrossPrice
 * again. Net and VAT of it come from PriceBreakdown at the rate of the catalog price.
 */
final class PromotionalPrice
{
    public static function apply(GrossPrice $catalog, PercentOff $percentOff): GrossPrice
    {
        // GrossPrice::MAX × 90 is far below the int range.
        $discount = intdiv($catalog->amount() * $percentOff->value(), 100);

        return GrossPrice::of(Money::of($catalog->amount() - $discount, $catalog->currency()));
    }
}
