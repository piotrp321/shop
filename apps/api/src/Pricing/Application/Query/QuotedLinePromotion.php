<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

final readonly class QuotedLinePromotion
{
    public function __construct(
        public string $promotionId,
        public string $name,
        public int $percentOff,
    ) {
    }
}
