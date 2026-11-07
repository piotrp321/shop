<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * Why a succeeded payment must be refunded by hand: the order was cancelled, the payment was executed after the
 * deadline, or another payment paid the order.
 */
enum RefundReason: string
{
    case OrderCancelled = 'order_cancelled';
    case PaidAfterDeadline = 'paid_after_deadline';
    case Duplicate = 'duplicate';
}
