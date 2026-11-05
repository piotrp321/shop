<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Identifier of an order, a UUID v7 the handler gives a new order. It is also the key of the order's stock reservation.
 */
final readonly class OrderId
{
    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self(UuidFormat::normalise($value, 'OrderId'));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
