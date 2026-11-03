<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

enum StockChangeSource: string
{
    /** A staff member in the panel. */
    case Staff = 'staff';
    /** The demo data. */
    case Seed = 'seed';
    /** Another module through the reservation port (a future Ordering). */
    case System = 'system';
}
