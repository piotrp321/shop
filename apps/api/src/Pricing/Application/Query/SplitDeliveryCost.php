<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The split of the gross cost of a delivery over the VAT rates of the goods of an order (DeliveryCostSplit), for the
 * placement of an order. Amounts in grosze.
 */
final readonly class SplitDeliveryCost
{
    /**
     * @param int             $costGross   the gross cost of the delivery, from 0
     * @param array<int, int> $goodsByRate the gross of the goods after discounts by VAT rate in basis points
     */
    public function __construct(
        public int $costGross,
        public array $goodsByRate,
    ) {
    }
}
