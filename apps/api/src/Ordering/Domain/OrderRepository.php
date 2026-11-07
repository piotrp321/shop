<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Orders, written in the transaction of the command that changes them. Orders are kept for ever.
 */
interface OrderRepository
{
    /**
     * Stores a new order with its lines.
     *
     * @throws IdempotencyKeyReused another order holds the key (a concurrent placement from another cart)
     */
    public function add(Order $order): void;

    /**
     * Stores the state of an order got from get() or findByIdempotencyKey(), if the stored version is still the one
     * it was read at.
     *
     * @throws OrderModifiedConcurrently
     */
    public function save(Order $order): void;

    /**
     * The order read from the database (also later in a transaction, it sees what others committed meanwhile).
     *
     * @throws OrderNotFound
     */
    public function get(OrderId $id): Order;

    /**
     * The order that used the key, read from the database (also within a transaction that waited for a lock, it sees
     * what others committed meanwhile); null when no order used it.
     */
    public function findByIdempotencyKey(IdempotencyKey $key): ?Order;
}
