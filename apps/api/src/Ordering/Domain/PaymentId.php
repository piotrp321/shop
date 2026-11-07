<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The id of the payment (Payment) that paid an order: a copy, never a reference.
 */
final readonly class PaymentId
{
    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self(UuidFormat::normalise($value, 'PaymentId'));
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
