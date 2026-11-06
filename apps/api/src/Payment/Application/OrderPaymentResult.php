<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Domain\RefundReason;

/**
 * What paying the order with a succeeded payment did: paid it, or found it paid by this payment before; otherwise the
 * money does not pay it (paid by another payment, executed after the deadline, the order cancelled).
 */
enum OrderPaymentResult
{
    case Paid;
    case AlreadyPaid;
    case PaidByAnotherPayment;
    case PaidAfterDeadline;
    case OrderCancelled;

    /**
     * Why the payment must be refunded by hand (P1, P5), or null when it paid the order.
     */
    public function refundReason(): ?RefundReason
    {
        return match ($this) {
            self::Paid, self::AlreadyPaid => null,
            self::PaidByAnotherPayment => RefundReason::Duplicate,
            self::PaidAfterDeadline => RefundReason::PaidAfterDeadline,
            self::OrderCancelled => RefundReason::OrderCancelled,
        };
    }
}
