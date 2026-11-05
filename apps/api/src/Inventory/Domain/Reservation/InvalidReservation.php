<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A reservation request that breaks the rules of its key or its lines. The message never contains the submitted value.
 */
final class InvalidReservation extends \DomainException implements InvalidInput
{
    public static function malformedKey(): self
    {
        return new self(\sprintf('A reservation key must have 1 to %d characters: letters, digits, ".", "_", ":" or "-".', ReservationKey::MAX_LENGTH));
    }

    public static function lineCountOutOfRange(): self
    {
        return new self(\sprintf('A reservation must have 1 to %d lines.', Reservation::MAX_LINES));
    }

    public static function quantityOutOfRange(): self
    {
        return new self(\sprintf('A reservation line must reserve 1 to %d units.', ReservationLine::MAX_QUANTITY));
    }

    public static function repeatedVariant(): self
    {
        return new self('A variant may appear in only one line of a reservation.');
    }
}
