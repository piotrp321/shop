<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * How many times in total a code may be used (decision D1): 1 to 1 000 000 active usages.
 */
final readonly class UsageLimit
{
    public const int MIN = 1;
    public const int MAX = 1_000_000;

    private function __construct(private int $value)
    {
    }

    /**
     * @throws InvalidDiscountCode
     */
    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw InvalidDiscountCode::usageLimit();
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function isReachedBy(int $activeUses): bool
    {
        return $activeUses >= $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
