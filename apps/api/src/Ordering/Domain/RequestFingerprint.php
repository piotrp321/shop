<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * What tells a repeated placement from another one with the same Idempotency-Key: SHA-256 of the cart reference
 * (`guest:<cartId>` or `customer:<sub>`), the contact, the address and the expected gross total, as normalised by
 * their value objects (trimmed, the phone as +48 and 9 digits), the discount code (normalised) when there is one, and
 * `delivery:<id>` of the delivery method (in lower case) when there is one; the prefix keeps the id apart from a code;
 * then `lines:` with the canonical form of the expected lines when there are some (ExpectedLines::canonical()).
 * The cart is part of it, so the same key and data from another cart is another request. A field is added only when
 * the request has it, so the fields of a request without them are exactly those of the time before (apps/api/CLAUDE.md,
 * "Transakcje między contextami").
 */
final readonly class RequestFingerprint
{
    private const string PATTERN = '/^[0-9a-f]{64}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @param string|null        $discountCode     trimmed, in upper case; null for none
     * @param string|null        $deliveryMethodId a UUID in lower case; null for none
     * @param ExpectedLines|null $expectedLines    the lines the checkout showed; null for none
     */
    public static function of(string $cartReference, Contact $contact, ShippingAddress $address, int $expectedTotalGross, ?string $discountCode = null, ?string $deliveryMethodId = null, ?ExpectedLines $expectedLines = null): self
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
        if (null !== $deliveryMethodId) {
            $fields[] = 'delivery:'.$deliveryMethodId;
        }
        if (null !== $expectedLines) {
            $fields[] = 'lines:'.$expectedLines->canonical();
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
