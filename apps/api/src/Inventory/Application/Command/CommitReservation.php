<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

/**
 * Takes the reserved units of an active reservation off the shelf (the order was shipped). Idempotent.
 */
final readonly class CommitReservation
{
    public function __construct(public string $reservationKey)
    {
    }
}
