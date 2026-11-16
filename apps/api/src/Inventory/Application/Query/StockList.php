<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

use App\Shared\Domain\BoundedCount;

final readonly class StockList
{
    /**
     * @param list<StockListItem> $items
     * @param string|null         $next  cursor of the next page, null on the last page
     * @param BoundedCount|null   $total how many products match the filters; only on the first page, else null
     */
    public function __construct(
        public array $items,
        public ?string $next,
        public ?BoundedCount $total,
    ) {
    }
}
