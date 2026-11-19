<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Application\Port\PaymentStartLock;
use App\Payment\Domain\OrderId;

/**
 * The locks of the payment starts in memory: the orders whose lock a concurrent start holds, and every attempt.
 */
final class InMemoryPaymentStartLocks implements PaymentStartLock
{
    /** @var list<string> the orders whose lock a concurrent start holds */
    public array $heldElsewhere = [];
    /** @var list<string> the orders of every attempt, in order */
    public array $tried = [];

    public function tryAcquire(OrderId $orderId): bool
    {
        $this->tried[] = $orderId->toString();

        return !\in_array($orderId->toString(), $this->heldElsewhere, true);
    }
}
