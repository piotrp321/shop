<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Shared\Domain\BoundedCount;

/**
 * The variants at each level of the stock list, counted up to {@see GetStockQueuesHandler::LIMIT}.
 */
final readonly class StockQueues
{
    public function __construct(
        public BoundedCount $outOfStockVariants,
        public BoundedCount $lowStockVariants,
    ) {
    }
}
