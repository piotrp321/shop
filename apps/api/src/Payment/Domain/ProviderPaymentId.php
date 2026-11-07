<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The id of the payment session at its provider (demo-transfer `transactionId`, demo-card `id`).
 */
final readonly class ProviderPaymentId
{
    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self(ProviderIdFormat::check($value, 'ProviderPaymentId'));
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
