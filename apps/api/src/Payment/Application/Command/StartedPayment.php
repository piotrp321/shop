<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

use App\Payment\Application\View\PaymentView;

/**
 * The payment a start ended with; replayed when it is the payment of an earlier start with the same key, order and
 * provider (as it is now).
 */
final readonly class StartedPayment
{
    public function __construct(
        public PaymentView $payment,
        public bool $replayed,
    ) {
    }
}
