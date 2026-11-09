<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * The smallest subtotal (gross of the effective prices, before the code; Q2) a code works from: 0,01 to 1 000 000 zł.
 */
final readonly class MinimumOrder
{
    public const int MIN = 1;
    public const int MAX = 100_000_000;

    private function __construct(private int $gross)
    {
    }

    /**
     * @throws InvalidDiscountCode
     */
    public static function of(int $gross): self
    {
        if ($gross < self::MIN || $gross > self::MAX) {
            throw InvalidDiscountCode::minimum();
        }

        return new self($gross);
    }

    public function gross(): int
    {
        return $this->gross;
    }

    public function equals(self $other): bool
    {
        return $this->gross === $other->gross;
    }
}
