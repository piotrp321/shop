<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * A discount code, a UUID v7 chosen by the caller.
 */
final readonly class DiscountCodeId
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower($value);
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException('DiscountCodeId must be a UUID.');
        }

        return new self($value);
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
