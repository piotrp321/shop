<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

/**
 * Gives back the reserved units of an active reservation. Idempotent.
 */
final readonly class ReleaseReservation
{
    public function __construct(public string $reservationKey)
    {
    }
}
