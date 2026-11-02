<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

/**
 * Reserves units of products for the caller, all lines or none. The key identifies the reservation and makes the
 * command idempotent: repeating it with the same lines returns the reservation as it is now.
 */
final readonly class ReserveStock
{
    /**
     * @param string                 $reservationKey 1 to 100 characters [A-Za-z0-9._:-], e.g. the order id
     * @param list<ReserveStockLine> $lines          1 to 100 lines, each product once
     */
    public function __construct(
        public string $reservationKey,
        public array $lines,
    ) {
    }
}
