<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

/**
 * An earlier promotion of a variant as the Omnibus price reads it: its period and its discount.
 */
final readonly class PriorPromotion
{
    public function __construct(
        public PromotionPeriod $period,
        public PercentOff $percentOff,
    ) {
    }
}
