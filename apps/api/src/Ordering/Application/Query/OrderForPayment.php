<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * An order as Payment sees it: no personal data and no token. The status is `new`, `paid` or `cancelled`; payable
 * tells whether a payment can be started now (new and before the deadline). The total is in grosze.
 */
final readonly class OrderForPayment
{
    public function __construct(
        public string $orderId,
        public string $number,
        public string $status,
        public int $totalGross,
        public string $currency,
        public \DateTimeImmutable $paymentDeadline,
        public bool $payable,
    ) {
    }
}
