<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The price of a variant as stored, at a VAT rate that is still the rate of its class. Amounts are in grosze.
 * `version` lets a consumer that copied the price (an order line) tell later that the price changed.
 */
final readonly class PriceSnapshot
{
    public function __construct(
        public string $variantId,
        public string $taxClass,
        public int $vatRateBp,
        public int $net,
        public int $vat,
        public int $gross,
        public string $currency,
        public int $version,
    ) {
    }
}
