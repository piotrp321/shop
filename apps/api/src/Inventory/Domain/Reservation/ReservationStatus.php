<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

enum ReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Committed = 'committed';
}
