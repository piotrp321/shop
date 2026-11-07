<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The Idempotency-Key of a start of a payment: 1 to 100 characters [A-Za-z0-9._:-], the format of the keys of
 * Ordering.
 */
final readonly class IdempotencyKey
{
    private const string PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidPaymentInput
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidPaymentInput::idempotencyKey();
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
