<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Reservation\ReservationStateConflict as ReservationStateConflictInDomain;
use App\Shared\Domain\Exception\Conflict;

/**
 * A transition the reservation can no longer make: committing a released one or releasing a committed one. The
 * exception of the reservation port: a caller outside Inventory depends only on its Application layer.
 */
final class ReservationStateConflict extends \DomainException implements Conflict
{
    public static function fromDomain(ReservationStateConflictInDomain $conflict): self
    {
        return new self($conflict->getMessage(), 0, $conflict);
    }
}
