<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

/**
 * What the confirmation page shows of an order and its newest payment. `canStartPayment`: the order is payable (new
 * and before the deadline); a new attempt cancels a pending payment at its provider first. Amounts in minor units.
 */
final readonly class PaymentStatusView
{
    public function __construct(
        public string $orderNumber,
        public string $orderStatus,
        public \DateTimeImmutable $paymentDeadline,
        public int $totalGross,
        public string $currency,
        public bool $canStartPayment,
        public ?LatestPaymentView $latestPayment,
    ) {
    }
}
