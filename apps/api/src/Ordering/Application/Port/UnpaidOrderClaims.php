<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\UnpaidOrderIdentity;

/**
 * The claims of the unpaid orders: one per new order, with the address of the client (the key of ClientIp), the
 * identity and the expiry of the order, written with the order and removed in the transaction that pays or cancels
 * it. The address is kept only here and only until then; no view reads it.
 */
interface UnpaidOrderClaims
{
    /**
     * The claims of the address that expire after $now, as committed (read under the lock of the address).
     */
    public function countByAddress(string $address, \DateTimeImmutable $now): int;

    /**
     * The claims of the identity that expire after $now, as committed (read under the lock of the identity).
     */
    public function countByIdentity(UnpaidOrderIdentity $identity, \DateTimeImmutable $now): int;

    /**
     * Writes the claim of an order already stored.
     */
    public function add(OrderId $orderId, string $address, UnpaidOrderIdentity $identity, \DateTimeImmutable $expiresAt): void;

    /**
     * Removes the claim of the order, if it has one.
     */
    public function remove(OrderId $orderId): void;
}
