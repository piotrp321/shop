<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A row of pricing_discount_code_usage: the usage of a historical code by an order, released when the order expired
 * unpaid.
 */
final readonly class CodeUsageSpec
{
    public function __construct(
        public string $id,
        public string $codeId,
        public \DateTimeImmutable $usedAt,
        public ?\DateTimeImmutable $releasedAt,
    ) {
    }
}
