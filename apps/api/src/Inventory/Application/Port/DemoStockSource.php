<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Application\Demo\DemoStockList;

interface DemoStockSource
{
    /**
     * @throws \InvalidArgumentException when the definition is missing or invalid
     */
    public function load(): DemoStockList;
}
