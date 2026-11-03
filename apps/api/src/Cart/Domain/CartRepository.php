<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * Carts for a change. Every change starts by locking the row of its cart, inside the transaction of the command, and
 * reads the cart as stored once the lock is held, so concurrent changes of one cart run one after another.
 */
interface CartRepository
{
    /**
     * The cart, locked; null when there is none.
     */
    public function lock(CartId $id): ?Cart;

    /**
     * The cart of the customer, locked; null when the customer has none.
     */
    public function lockForCustomer(string $customerId): ?Cart;

    /**
     * The cart of the customer, locked; created (empty, open) when the customer has none. Two first changes of one
     * customer at once end with one cart.
     */
    public function lockOrCreateForCustomer(string $customerId, \DateTimeImmutable $now): Cart;

    /**
     * Stores a new cart.
     */
    public function add(Cart $cart): void;

    /**
     * Stores the state of a cart got from one of the lock methods.
     */
    public function save(Cart $cart): void;
}
