<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * What tells a repeated placement from another one with the same Idempotency-Key: SHA-256 of the cart reference
 * (`guest:<cartId>` or `customer:<sub>`), the contact, the address and the expected gross total, as normalised by
 * their value objects (trimmed, the phone as +48 and 9 digits). The cart is part of it, so the same key and data from
 * another cart is another request.
 */
final readonly class RequestFingerprint
{
    private const string PATTERN = '/^[0-9a-f]{64}$/D';

    private function __construct(private string $value)
    {
    }

    public static function of(string $cartReference, Contact $contact, ShippingAddress $address, int $expectedTotalGross): self
    {
        $normalised = json_encode([
            $cartReference,
            $contact->email()->toString(),
            $contact->fullName(),
            $contact->phone(),
            $address->street(),
            $address->postalCode(),
            $address->city(),
            $address->country(),
            $expectedTotalGross,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        return new self(hash('sha256', $normalised));
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
