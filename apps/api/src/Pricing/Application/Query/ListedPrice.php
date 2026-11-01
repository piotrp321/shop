<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * A stored price as the panel shows it. Amounts are in grosze, the rate in basis points.
 */
final readonly class ListedPrice
{
    /**
     * @param bool $vatRateOutdated the stored rate is no longer the rate of the price's class
     */
    public function __construct(
        public int $gross,
        public int $net,
        public int $vat,
        public int $vatRate,
        public string $currency,
        public int $version,
        public \DateTimeImmutable $updatedAt,
        public bool $vatRateOutdated,
    ) {
    }
}
