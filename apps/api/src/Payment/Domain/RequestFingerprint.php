<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What tells a repeated start of a payment from another one with the same Idempotency-Key: SHA-256 of the order and
 * the provider.
 */
final readonly class RequestFingerprint
{
    private const string PATTERN = '/^[0-9a-f]{64}$/D';

    private function __construct(private string $value)
    {
    }

    public static function of(OrderId $orderId, ProviderCode $provider): self
    {
        return new self(hash('sha256', json_encode([$orderId->toString(), $provider->value], \JSON_THROW_ON_ERROR)));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('A request fingerprint is 64 hexadecimal digits.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
