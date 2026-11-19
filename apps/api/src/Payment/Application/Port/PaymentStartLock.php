<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Domain\OrderId;

/**
 * The lock of the payment starts of one order, held until the end of the transaction of the start. StartPayment takes
 * it without waiting right after the replay: a concurrent start of the order (cancelling the pending payment or
 * opening its session at the operator) holds it, so this start ends at once instead of waiting on the unique indexes
 * with its thread and connection. Starts of different orders do not wait for each other.
 *
 * Must be called inside the transaction of the start (command.bus).
 */
interface PaymentStartLock
{
    /**
     * @return bool false: a concurrent start of the order holds the lock
     */
    public function tryAcquire(OrderId $orderId): bool;
}
