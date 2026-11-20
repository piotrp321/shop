<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The share of the cost of a delivery at a VAT rate in basis points, amounts in grosze; net + VAT is the gross.
 */
final readonly class DeliveryCostShare
{
    public function __construct(
        public int $rateBp,
        public int $gross,
        public int $net,
        public int $vat,
    ) {
    }
}
