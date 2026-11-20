<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;

/**
 * A pending payment as PendingPayments reads it: the order it pays (the keyset of the walk; an order has at most one
 * pending payment) and its id.
 */
final readonly class PendingPayment
{
    public function __construct(
        public OrderId $orderId,
        public PaymentId $paymentId,
    ) {
    }
}
