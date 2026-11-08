<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * An order as Payment sees it: no personal data and no token. statusForPayment is the status of the order from the
 * point of view of a payment, `new`, `paid` or `cancelled` (an order shipped, delivered or returned is `paid`), so
 * Payment never learns the statuses of the panel; payable tells whether a payment can be started now (new and before
 * the deadline). The total is in grosze.
 */
final readonly class OrderForPayment
{
    public function __construct(
        public string $orderId,
        public string $number,
        public string $statusForPayment,
        public int $totalGross,
        public string $currency,
        public \DateTimeImmutable $paymentDeadline,
        public bool $payable,
    ) {
    }
}
