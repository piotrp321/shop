<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Who placed an order: a guest, or a customer by the `sub` of the customer's token.
 */
final readonly class OrderOwner
{
    public const int MAX_CUSTOMER_ID_LENGTH = 255;

    private function __construct(private ?string $customerId)
    {
    }

    public static function guest(): self
    {
        return new self(null);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function customer(string $customerId): self
    {
        $length = mb_strlen($customerId);
        if ($length < 1 || $length > self::MAX_CUSTOMER_ID_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A customer id has 1 to %d characters.', self::MAX_CUSTOMER_ID_LENGTH));
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
