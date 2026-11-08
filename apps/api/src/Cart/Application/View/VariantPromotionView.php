<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * The promotion of a variant of the list: its end (the period is [start, end)) and its Omnibus price (the lowest
 * gross of the 30 days before the start, in grosze; null when not asked for or the variant had no price before it).
 */
final readonly class VariantPromotionView
{
    public function __construct(
        public \DateTimeImmutable $endsAt,
        public ?int $lowestPriorGross,
    ) {
    }
}
