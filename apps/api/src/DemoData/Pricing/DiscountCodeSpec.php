<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;

/**
 * A generated historical discount code: a row of pricing_discount_code. It has ended before the anchor and is not
 * disabled (so its status is `expired`); the usages are made by the orders.
 */
final readonly class DiscountCodeSpec
{
    public function __construct(
        public string $id,
        public DiscountCodeValue $code,
        public DiscountValue $value,
        public ?MinimumOrder $minimumOrder,
        public ValidityPeriod $period,
        public ?UsageLimit $usageLimit,
        public bool $enabled,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
