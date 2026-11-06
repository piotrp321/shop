<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Application\OrderBusy;
use App\Payment\Application\OrderPaymentResult;
use App\Payment\Domain\Amount;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;

/**
 * Ordering pays its orders: called in the transaction of the webhook, so the payment and the status of the order
 * change together or not at all.
 */
interface OrderPayments
{
    /**
     * Pays the order with the payment executed at the provider at `executedAt`, if Ordering lets it.
     *
     * @throws OrderBusy the order changed concurrently; a retry of the webhook reads it again
     */
    public function markPaid(OrderId $orderId, PaymentId $paymentId, Amount $amount, \DateTimeImmutable $executedAt): OrderPaymentResult;
}
