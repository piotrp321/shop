<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\UnpaidOrderIdentity;

/**
 * Serialises the placements of one address and of one identity for the rest of the transaction, so the unpaid orders
 * of each are counted and claimed by one placement at a time. Locks the address, then the identity: always in this
 * order, after the lock of the cart and before the code and the stock.
 */
interface UnpaidOrderLock
{
    /**
     * @throws \LogicException outside a transaction
     */
    public function lock(string $address, UnpaidOrderIdentity $identity): void;
}
