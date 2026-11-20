<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Application\Port\PendingPayment;

/**
 * One batch of pending payments as AbandonedPayments judged it: the abandoned ones (to close), and the ones whose order
 * Ordering could not read, with the reason (never closed in this run: an error for the operator).
 */
final readonly class AbandonedPaymentBatch
{
    /**
     * @param list<PendingPayment>                $abandoned
     * @param list<array{PendingPayment, string}> $unreadable the payment and the reason
     */
    public function __construct(
        public array $abandoned,
        public array $unreadable,
    ) {
    }
}
