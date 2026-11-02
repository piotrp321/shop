<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

/**
 * Removes all stocks, reservations and the stock history. Only for the demo data (`inventory:seed-demo`) and tests:
 * nothing else ever deletes history.
 */
interface InventoryReset
{
    public function reset(): void;
}
