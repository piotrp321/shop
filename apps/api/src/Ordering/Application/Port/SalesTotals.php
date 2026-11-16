<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * The number and the gross total (in grosze) of the orders paid in a period.
 */
final readonly class SalesTotals
{
    public function __construct(public int $orderCount, public int $gross)
    {
    }
}
