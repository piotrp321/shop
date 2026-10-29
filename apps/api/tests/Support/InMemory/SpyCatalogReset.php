<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\CatalogReset;

final class SpyCatalogReset implements CatalogReset
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }
}
