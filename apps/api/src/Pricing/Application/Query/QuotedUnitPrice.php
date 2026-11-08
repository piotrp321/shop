<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

final readonly class QuotedUnitPrice
{
    public function __construct(
        public int $net,
        public int $vat,
        public int $gross,
        public int $vatRateBp,
        public string $taxClass,
    ) {
    }
}
