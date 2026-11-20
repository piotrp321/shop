<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * The one fixed gross price of a method in grosze (P5): 0 to 999 999,99 zł, the upper bound of a price of the catalog.
 */
final readonly class DeliveryPrice
{
    public const int MIN = 0;
    public const int MAX = 99_999_999;

    private function __construct(private int $gross)
    {
    }

    /**
     * @throws InvalidDeliveryMethod
     */
    public static function of(int $gross): self
    {
        if ($gross < self::MIN || $gross > self::MAX) {
            throw InvalidDeliveryMethod::priceRange();
        }

        return new self($gross);
    }

    public function gross(): int
    {
        return $this->gross;
    }
}
