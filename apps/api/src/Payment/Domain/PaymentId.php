<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * Identifier of a payment, a UUID v7 (time-ordered) the handler gives a new payment.
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
