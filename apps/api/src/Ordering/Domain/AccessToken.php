<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The secret a guest reaches the order with (starting a payment, reading its status): 32 random bytes in base64url
 * without padding, 43 characters. The handler of the placement makes it; a customer order has none.
 */
final readonly class AccessToken
{
    private const string PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('An access token is 43 characters of base64url.');
        }

        return new self($value);
    }

    /**
     * Compares in constant time, so the time of a refusal tells nothing about the token.
     */
    public function matches(string $candidate): bool
    {
        return hash_equals($this->value, $candidate);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
