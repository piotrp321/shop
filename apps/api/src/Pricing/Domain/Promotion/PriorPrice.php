<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Pricing\Domain\Price\GrossPrice;

/**
 * An entry of the price history of a variant as the Omnibus price reads it: the catalog gross from the moment of the
 * change on. The VAT rate does not matter: an entry at a rate that is no longer the rate of its class was still the
 * price the store showed.
 */
final readonly class PriorPrice
{
    public function __construct(
        public \DateTimeImmutable $changedAt,
        public GrossPrice $gross,
    ) {
    }
}
