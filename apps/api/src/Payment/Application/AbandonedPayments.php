<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Application\Port\PayableOrders;
use App\Payment\Application\Port\PendingPayment;
use App\Payment\Application\Port\PendingPayments;

/**
 * The pending payments of orders Ordering no longer lets pay (docs/features/async-reliability/spec.md, D5, W6): an order
 * cancelled, paid (by another payment) or new past its deadline, or one Ordering does not know. Read in batches by the
 * keyset of the order id, with one question to Ordering about the orders of the whole batch.
 *
 * An order that cannot be paid never becomes payable again (its status never goes back to new and its deadline never
 * moves), so the answer of Ordering still holds when the closing of the payment locks it later; what may change in
 * between is the payment itself (a webhook or a new start), which CloseAbandonedPayment checks under the lock.
 */
final readonly class AbandonedPayments
{
    public function __construct(
        private PendingPayments $pending,
        private PayableOrders $orders,
    ) {
    }

    /**
     * The abandoned payments of each batch of at most $batchSize pending payments (an empty list for a batch of only
     * payable orders), until a batch is empty.
     *
     * @param int $batchSize 1 to 100, the most orders of one question to Ordering
     *
     * @return \Generator<int, list<PendingPayment>>
     */
    public function batches(int $batchSize): \Generator
    {
        $after = null;
        while ([] !== $page = $this->pending->page($after, $batchSize)) {
            $payability = $this->orders->payabilityOf(array_map(static fn (PendingPayment $payment) => $payment->orderId, $page));
            yield array_values(array_filter(
                $page,
                static fn (PendingPayment $payment): bool => !($payability[$payment->orderId->toString()] ?? null)?->payable,
            ));
            $after = $page[\count($page) - 1]->orderId;
        }
    }
}
