<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OrderingReset;

final class SpyOrderingReset implements OrderingReset
{
    public int $resets = 0;

    public function reset(): void
    {
        ++$this->resets;
    }
}
