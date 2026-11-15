<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\BoundedCount;

final readonly class BackofficeOrderList
{
    /**
     * @param list<BackofficeOrderListItem> $items
     * @param string|null                   $nextCursor the `after` of the next page, null on the last one
     * @param BoundedCount|null             $total      how many orders match the filters; only on the first page (no `after`), else null
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?BoundedCount $total,
    ) {
    }
}
