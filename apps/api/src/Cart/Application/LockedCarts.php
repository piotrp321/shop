<?php

declare(strict_types=1);

namespace App\Cart\Application;

use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartNotFound;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\GuestCartMerged;

/**
 * The cart a command changes, locked and open for the change (Cart::openForChange()). A guest names a guest cart:
 * an unknown id, an id that is no UUID and the id of a customer cart are all an unknown cart.
 */
final readonly class LockedCarts
{
    public function __construct(private CartRepository $carts)
    {
    }

    /**
     * @param bool $createForCustomer whether a customer without a cart gets one; without, null is returned
     *
     * @throws CartNotFound
     * @throws GuestCartMerged
     */
    public function forChange(CartRef $ref, \DateTimeImmutable $now, bool $createForCustomer): ?Cart
    {
        if (null !== $ref->customerId) {
            $cart = $createForCustomer
                ? $this->carts->lockOrCreateForCustomer($ref->customerId, $now)
                : $this->carts->lockForCustomer($ref->customerId);
        } else {
            $cart = $this->carts->lock(self::guestCartId($ref));
            if (null === $cart || !$cart->owner()->isGuest()) {
                throw new CartNotFound();
            }
        }

        $cart?->openForChange($now);

        return $cart;
    }

    /**
     * @throws CartNotFound
     */
    public static function guestCartId(CartRef $ref): CartId
    {
        try {
            return CartId::fromString($ref->cartId ?? '');
        } catch (\InvalidArgumentException) {
            throw new CartNotFound();
        }
    }
}
