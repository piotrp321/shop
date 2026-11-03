<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

/**
 * The identifier of a reservation chosen by its caller (the order id of a future Ordering) and its idempotency key.
 */
final readonly class ReservationKey
{
    public const int MAX_LENGTH = 100;
    private const string PATTERN = '/^[A-Za-z0-9._:-]{1,100}$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidReservation
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidReservation::malformedKey();
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
