<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Application\OrderPaymentResult;
use App\Payment\Application\Port\OrderPayments;
use App\Payment\Domain\Amount;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentId;

/**
 * Ordering as the webhook sees it: answers every payment with the given result or throws the given failure, recording
 * every call.
 */
final class FakeOrderPayments implements OrderPayments
{
    /** @var list<array{OrderId, PaymentId, Amount, \DateTimeImmutable}> */
    public array $calls = [];
    public OrderPaymentResult $result = OrderPaymentResult::Paid;
    public ?\Throwable $failure = null;

    public function markPaid(OrderId $orderId, PaymentId $paymentId, Amount $amount, \DateTimeImmutable $executedAt): OrderPaymentResult
    {
        $this->calls[] = [$orderId, $paymentId, $amount, $executedAt];
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->result;
    }
}
