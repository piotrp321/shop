<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\VatRates;

/**
 * A stored price as the panel shows it: in the price list, and as the result of a change. Amounts are in grosze, the
 * rate in basis points.
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

    /**
     * The price as it is stored: after a save, its version and time are the ones written.
     */
    public static function of(VariantPrice $price, VatRates $rates): self
    {
        $breakdown = $price->breakdown();

        return new self(
            $breakdown->gross()->amount(),
            $breakdown->net()->amount(),
            $breakdown->vat()->amount(),
            $breakdown->rate()->basisPoints(),
            $breakdown->currency()->value,
            $price->version(),
            $price->updatedAt(),
            !$price->isCurrent($rates),
        );
    }
}
