<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * The value of the goods after discounts (gross, grosze) from which a method is free (P6): 0,01 to 999 999 999,99 zł.
 * A method without a threshold has none (null), never a threshold of 0.
 */
final readonly class FreeDeliveryThreshold
{
    public const int MIN = 1;
    public const int MAX = 99_999_999_999;

    private function __construct(private int $gross)
    {
    }

    /**
     * @throws InvalidDeliveryMethod
     */
    public static function of(int $gross): self
    {
        if ($gross < self::MIN || $gross > self::MAX) {
            throw InvalidDeliveryMethod::freeFromRange();
        }

        return new self($gross);
    }

    /**
     * @throws InvalidDeliveryMethod
     */
    public static function optional(?int $gross): ?self
    {
        return null === $gross ? null : self::of($gross);
    }

    public function gross(): int
    {
        return $this->gross;
    }

    /**
     * Whether goods of this value get the method free: from the threshold, inclusive.
     */
    public function isReachedBy(int $goodsGross): bool
    {
        return $goodsGross >= $this->gross;
    }
}
