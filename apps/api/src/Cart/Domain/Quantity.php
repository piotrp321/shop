<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * Units of one product in a cart, from 1 to Cart::MAX_QUANTITY.
 */
final readonly class Quantity
{
    public const int MIN = 1;

    private function __construct(private int $value)
    {
    }

    /**
     * @throws InvalidQuantity
     */
    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > Cart::MAX_QUANTITY) {
            throw new InvalidQuantity();
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }
}
