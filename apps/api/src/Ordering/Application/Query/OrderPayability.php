<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * An order as payment:close-abandoned sees it: whether a payment can be started for it now (new and before the
 * deadline, decided by Ordering) and its status for Payment (`new`, `paid` or `cancelled`, as in OrderForPayment). No
 * number, total or personal data.
 */
final readonly class OrderPayability
{
    public function __construct(
        public string $orderId,
        public bool $payable,
        public string $statusForPayment,
    ) {
    }
}
