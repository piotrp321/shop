<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Cart\Application\CartRef;
use App\Cart\Application\LockedCarts;
use App\Cart\Application\Port\CartReader;
use App\Cart\Domain\Cart;
use App\Cart\Domain\CartNotFound;

/**
 * The cart a query reads, without a lock: null for a customer without a cart; an unknown guest cart, or the id of
 * a customer cart named as a guest cart, is CartNotFound. Expiry and merging are the cart's rules (Cart::linesAt()).
 */
final class ReadableCarts
{
    /**
     * @throws CartNotFound
     */
    public static function find(CartReader $reader, CartRef $ref): ?Cart
    {
        if (null !== $ref->customerId) {
            return $reader->findForCustomer($ref->customerId);
        }

        $cart = $reader->find(LockedCarts::guestCartId($ref));
        if (null === $cart || !$cart->owner()->isGuest()) {
            throw new CartNotFound();
        }

        return $cart;
    }
}
