<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * Identifier of a cart. A new one is a random UUID v4: the id of a guest cart is also the key to it.
 */
final readonly class CartId
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower($value);
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('CartId must be a UUID.');
        }

        return new self($value);
    }

    /**
     * 122 random bits from the CSPRNG, with the version (4) and variant (RFC 9562) bits set.
     */
    public static function generate(): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return new self(\sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)));
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
