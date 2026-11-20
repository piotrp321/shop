<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Basket;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\LineBreakdown;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Tax\VatRate;

/**
 * The split of the gross cost of a delivery over the VAT rates of the goods of an order (docs/features/delivery/spec.md,
 * W3 and P4): the cost is shared in proportion to the gross of the goods after discounts at each rate, and each share
 * takes the rate of its goods.
 *
 * The rates go in descending order; the cost is allocated over their values by BasketQuote::allocate (the shares add
 * up to the cost, a tie of remainders gives the grosz to the earlier, so higher, rate); net and VAT of each share come
 * from PriceBreakdown::ofLineGross. A share of 0 grosze is left out, so a free delivery has no share.
 */
final class DeliveryCostSplit
{
    private function __construct()
    {
    }

    /**
     * @param int             $cost        the gross cost of the delivery in grosze, from 0 to PriceBreakdown::MAX_LINE_GROSS
     * @param array<int, int> $goodsByRate the gross of the goods after discounts in grosze (≥ 0) by rate in basis points;
     *                                     at least one is above 0
     *
     * @return list<LineBreakdown> the shares above 0, by rate in descending order
     *
     * @throws \InvalidArgumentException for a cost or a value out of its range, a rate out of 0–10000 basis points, or
     *                                   goods worth nothing
     */
    public static function of(int $cost, array $goodsByRate): array
    {
        if ($cost < 0 || $cost > PriceBreakdown::MAX_LINE_GROSS) {
            throw new \InvalidArgumentException('The cost of a delivery must be between 0 and the largest value of a line.');
        }
        krsort($goodsByRate);
        $rates = [];
        foreach ($goodsByRate as $basisPoints => $goods) {
            if ($goods < 0) {
                throw new \InvalidArgumentException('The goods at a VAT rate must not be worth less than 0.');
            }
            $rates[] = VatRate::fromBasisPoints($basisPoints);
        }
        $values = array_values($goodsByRate);
        $subtotal = array_sum($values);
        if ($subtotal <= 0) {
            throw new \InvalidArgumentException('The goods of an order must be worth more than 0.');
        }

        $shares = [];
        foreach (BasketQuote::allocate($values, $subtotal, $cost) as $index => $share) {
            if ($share > 0) {
                $shares[] = PriceBreakdown::ofLineGross(Money::of($share, Currency::PLN), $rates[$index]);
            }
        }

        return $shares;
    }
}
