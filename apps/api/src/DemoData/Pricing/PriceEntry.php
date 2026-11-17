<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

/**
 * One entry of the price history of a variant: a row of pricing_price_history. The last entry of a variant is also its
 * current price (pricing_variant_price).
 */
final readonly class PriceEntry
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $changedAt,
        public int $gross,
        public int $net,
        public int $vat,
        public int $rateBasisPoints,
        public string $taxClass,
        public string $source,
        public ?string $actorId,
        public ?string $actorName,
    ) {
    }
}
