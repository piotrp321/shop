<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionPeriod;

/**
 * The promotion a variant is in at a moment.
 */
final readonly class ActivePromotion
{
    public function __construct(
        public string $promotionId,
        public string $name,
        public PercentOff $percentOff,
        public PromotionPeriod $period,
    ) {
    }
}
