<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A row of ordering_order_line: the copies of the product and of the effective price at the placement, the share of the
 * discount of the code and the amounts after it, the copy of the promotion running then.
 */
final readonly class OrderLineSpec
{
    public function __construct(
        public string $id,
        public int $position,
        public string $variantId,
        public string $productId,
        public string $productName,
        public string $variantLabel,
        public int $quantity,
        public int $unitNet,
        public int $unitVat,
        public int $unitGross,
        public int $vatRateBp,
        public string $taxClass,
        public int $lineNet,
        public int $lineVat,
        public int $lineGross,
        public int $discountGross,
        public ?string $promotionId,
        public ?string $promotionName,
        public ?int $promotionPercentOff,
        public ?int $regularUnitGross,
    ) {
    }
}
