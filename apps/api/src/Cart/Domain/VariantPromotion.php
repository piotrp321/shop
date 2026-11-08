<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * The promotion a variant is in, as Pricing gives it with the price: until when it lasts and the lowest price of the
 * 30 days before its start (the Omnibus price; null when Pricing did not compute it, or the variant had no price
 * before the start). The cart decides nothing on it: the price of the variant is already the promotional one.
 */
final readonly class VariantPromotion
{
    public function __construct(
        public \DateTimeImmutable $endsAt,
        public ?Money $lowestPriorGross,
    ) {
    }
}
