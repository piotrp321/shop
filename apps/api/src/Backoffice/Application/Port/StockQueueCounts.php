<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

use App\Shared\Domain\BoundedCount;

final readonly class StockQueueCounts
{
    public function __construct(
        public BoundedCount $outOfStockVariants,
        public BoundedCount $lowStockVariants,
    ) {
    }
}
