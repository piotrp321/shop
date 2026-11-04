<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * Who a cart belongs to: a guest (known only by the cart id) or a customer, by the `sub` of the customer's token.
 */
final readonly class CartOwner
{
    public const int MAX_CUSTOMER_ID_LENGTH = 255;

    private function __construct(private ?string $customerId)
    {
    }

    public static function guest(): self
    {
        return new self(null);
    }

    public static function customer(string $customerId): self
    {
        if ('' === $customerId || \strlen($customerId) > self::MAX_CUSTOMER_ID_LENGTH) {
            throw new \InvalidArgumentException('A customer id must have 1 to 255 characters.');
        }

        return new self($customerId);
    }

    public function isGuest(): bool
    {
        return null === $this->customerId;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }
}
