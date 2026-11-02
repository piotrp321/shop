<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Application\Port\InventoryReset;

final class SpyInventoryReset implements InventoryReset
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }
}
