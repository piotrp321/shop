<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Demo;

use App\Inventory\Application\Port\InventoryReset;
use Doctrine\DBAL\Connection;

final readonly class DbalInventoryReset implements InventoryReset
{
    public function __construct(private Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->connection->executeStatement('TRUNCATE inventory_reservation_line, inventory_reservation, inventory_stock_movement, inventory_stock_item');
    }
}
