<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

use App\Shared\Domain\Exception\Conflict;

/**
 * A transition a reservation can no longer make: committing a released one or releasing a committed one.
 */
final class ReservationStateConflict extends \DomainException implements Conflict
{
    public static function alreadyReleased(): self
    {
        return new self('The reservation was released and can no longer be committed.');
    }

    public static function alreadyCommitted(): self
    {
        return new self('The reservation was committed and can no longer be released.');
    }
}
