<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Correction = 'correction';
    case Reservation = 'reservation';
    case Release = 'release';
    case Commit = 'commit';
}
