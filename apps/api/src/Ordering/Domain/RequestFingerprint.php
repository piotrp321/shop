<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * What tells a repeated placement from another one with the same Idempotency-Key: SHA-256 of the cart reference
 * (`guest:<cartId>` or `customer:<sub>`), the contact, the address and the expected gross total, as normalised by
 * their value objects (trimmed, the phone as +48 and 9 digits), and the discount code (normalised) when there is one.
 * The cart is part of it, so the same key and data from another cart is another request. Without a code the fields are
 * exactly those of the time before discount codes, so a retry with a key of that time is still a replay.
 */
final readonly class RequestFingerprint
{
    private const string PATTERN = '/^[0-9a-f]{64}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @param string|null $discountCode trimmed, in upper case; null for none
     */
    public static function of(string $cartReference, Contact $contact, ShippingAddress $address, int $expectedTotalGross, ?string $discountCode = null): self
    {
        $fields = [
            $cartReference,
            $contact->email()->toString(),
            $contact->fullName(),
            $contact->phone(),
            $address->street(),
            $address->postalCode(),
            $address->city(),
            $address->country(),
            $expectedTotalGross,
        ];
        if (null !== $discountCode) {
            $fields[] = $discountCode;
        }
        $normalised = json_encode($fields, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

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
