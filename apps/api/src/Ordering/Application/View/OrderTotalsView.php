<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

final readonly class OrderTotalsView
{
    public function __construct(
        public int $net,
        public int $vat,
        public int $gross,
        public string $currency,
    ) {
    }
}
