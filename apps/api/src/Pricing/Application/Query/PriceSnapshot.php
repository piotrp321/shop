<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The effective price of a variant now, from a catalog price at a VAT rate that is still the rate of its class.
 * Amounts are in grosze. Net, VAT and gross are the price to pay: the promotional price in an active promotion
 * (`promotion`), else the catalog price; `regularGross` is the catalog gross. `version` is the version of the catalog
 * price: it lets a consumer that copied the price (an order line) tell later that the price changed.
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
        public int $regularGross,
        public ?PricePromotion $promotion,
    ) {
    }
}
