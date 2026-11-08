<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * An order in the history of a customer. The gross total is in grosze; the item count is the number of units of its
 * lines; the payment deadline has passed (computed against the clock) only for a new order.
 */
final readonly class CustomerOrderListItem
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
        public bool $paymentDeadlinePassed,
    ) {
    }
}
