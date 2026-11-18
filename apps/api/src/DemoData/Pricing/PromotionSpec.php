<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionPeriod;

/**
 * A generated promotion: a row of pricing_promotion with its variants (pricing_promotion_variant). Every generated
 * promotion has ended before the anchor.
 */
final readonly class PromotionSpec
{
    /**
     * @param list<string> $variantIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public PercentOff $percentOff,
        public PromotionPeriod $period,
        public \DateTimeImmutable $createdAt,
        public array $variantIds,
    ) {
    }
}
