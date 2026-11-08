<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * The promotion that gives the price of a snapshot. `lowestPriorGross` is the Omnibus price (the lowest effective
 * gross of the 30 days before the start, in grosze): computed only when the query asks for it, and null also when
 * the variant had no price before the start.
 */
final readonly class PricePromotion
{
    public function __construct(
        public string $promotionId,
        public string $name,
        public int $percentOff,
        public \DateTimeImmutable $endsAt,
        public ?int $lowestPriorGross,
    ) {
    }
}
