<?php

declare(strict_types=1);

namespace App\Cart\Application;

/**
 * Which cart a request is about: a guest cart by its id (the key to it), or the cart of a customer by the `sub` of
 * the customer's token. A customer never names the id of a cart.
 */
final readonly class CartRef
{
    private function __construct(
        public ?string $cartId,
        public ?string $customerId,
    ) {
    }

    public static function guest(string $cartId): self
    {
        return new self($cartId, null);
    }

    public static function customer(string $customerId): self
    {
        return new self(null, $customerId);
    }
}
