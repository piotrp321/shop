<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * Where an active method is shown among the others, ascending: 0 to 999 (methods of one position by name).
 */
final readonly class DeliveryPosition
{
    public const int MIN = 0;
    public const int MAX = 999;

    private function __construct(private int $value)
    {
    }

    /**
     * @throws InvalidDeliveryMethod
     */
    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw InvalidDeliveryMethod::position();
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }
}
