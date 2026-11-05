<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\CartReference;
use App\Shared\Domain\Exception\NotFound;

/**
 * The cart an order is placed from, in the transaction of the placement (Cart's commands, nested).
 */
interface OrderCart
{
    /**
     * Locks the cart until the end of the transaction, so other placements and changes of it wait.
     *
     * @return list<CartLineToOrder> in the order they were added; none for a customer without a cart or with an
     *                               expired one
     *
     * @throws NotFound an unknown, expired or merged guest cart, or the id of a customer cart (Cart's own exception)
     */
    public function lockForOrder(CartReference $cart): array;

    /**
     * Removes every line of the cart and renews its expiry; a guest cart stays open.
     *
     * @throws NotFound
     */
    public function clearAfterOrder(CartReference $cart): void;
}
