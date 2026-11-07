<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * An order as the history of a customer reads it: the amount in grosze, the number of units of its lines.
 */
final readonly class CustomerListedOrder
{
    public function __construct(
        public string $orderId,
        public string $number,
        public \DateTimeImmutable $placedAt,
        public string $status,
        public int $totalGross,
        public string $currency,
        public int $itemCount,
        public \DateTimeImmutable $paymentDeadline,
    ) {
    }
}
