<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Domain\OrderOwner;

/**
 * Which cart an order is placed from: a guest cart by its id (from the signed cookie of the store), or the cart of
 * a customer by the `sub` of the customer's token.
 */
final readonly class CartReference
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

    /**
     * `guest:<cartId>` (the id in lower case) or `customer:<sub>`: the cart part of the request fingerprint.
     */
    public function normalised(): string
    {
        return null !== $this->customerId ? 'customer:'.$this->customerId : 'guest:'.strtolower($this->cartId ?? '');
    }

    /**
     * @throws \InvalidArgumentException a customer id of more than 255 characters
     */
    public function owner(): OrderOwner
    {
        return null !== $this->customerId ? OrderOwner::customer($this->customerId) : OrderOwner::guest();
    }
}
