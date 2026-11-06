<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\PaymentRepository;

/**
 * A repository that sees no payment yet refuses to add one, as the unique indexes do when a concurrent start wrote
 * its payment and committed meanwhile.
 */
final class RefusingPayments implements PaymentRepository
{
    public function add(Payment $payment): void
    {
        throw PaymentInProgress::concurrentStart();
    }

    public function save(Payment $payment): void
    {
        throw new \LogicException('Nothing to save.');
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Payment
    {
        return null;
    }

    public function lockPendingOfOrder(OrderId $orderId): ?Payment
    {
        return null;
    }

    public function latestOfOrder(OrderId $orderId): ?Payment
    {
        return null;
    }
}
