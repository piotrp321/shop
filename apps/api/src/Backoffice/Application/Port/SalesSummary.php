<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

/**
 * The sales of the dashboard, from the module that owns the orders. Asked only for a staff member who may see them
 * (the caller decided by the voter of that module).
 */
interface SalesSummary
{
    public function summary(): SalesFigures;
}
