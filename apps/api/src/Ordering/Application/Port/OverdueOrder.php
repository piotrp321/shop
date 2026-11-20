<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * A new order past its deadline as OverdueOrderIds reads it: its id and the moment it was placed, which together are the
 * keyset of the walk.
 */
final readonly class OverdueOrder
{
    public function __construct(
        public string $orderId,
        public \DateTimeImmutable $placedAt,
    ) {
    }
}
