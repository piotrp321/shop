<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

final readonly class ReservationLineView
{
    public function __construct(
        public string $variantId,
        public int $quantity,
    ) {
    }
}
