<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderId;

/**
 * Schedules ExpireUnpaidOrder of an order for a moment, in the transaction of the caller: the message is stored with
 * the order (or the change of it) or not at all, and waits for its moment even while no worker runs.
 */
interface UnpaidOrderExpiry
{
    public function schedule(OrderId $orderId, \DateTimeImmutable $at): void;
}
