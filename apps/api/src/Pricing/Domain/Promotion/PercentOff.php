<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

/**
 * The discount of a promotion: a whole percent from 1 to 90 (decision Q2).
 */
final readonly class PercentOff
{
    public const int MIN = 1;
    public const int MAX = 90;

    private function __construct(private int $value)
    {
    }

    /**
     * @throws InvalidPromotion
     */
    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw InvalidPromotion::percentOff();
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
