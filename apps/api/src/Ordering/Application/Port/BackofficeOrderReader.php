<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Shared\Domain\BoundedCount;

/**
 * Reads the orders for the list of the panel, without locks. Read-only.
 */
interface BackofficeOrderReader
{
    /**
     * The orders that match the filter, strictly after the cursor, in the order of the filter: by placement or by the
     * amount (placed_at or total_gross, then id, in the direction of the sort), or, for the pending refunds, the
     * nearest due date first (refund_due_at, then id, ascending).
     *
     * @param BackofficeOrderCursor|null $after of the same sort as the filter
     * @param int                        $limit 1 or more
     *
     * @return list<ListedOrder> at most $limit
     */
    public function page(BackofficeOrderFilter $filter, ?BackofficeOrderCursor $after, int $limit): array;

    /**
     * How many orders match the filter, whatever the cursor of a page: counted up to $limit (10 000 for a list, at most
     * $limit + 1 rows are read), never the whole table.
     *
     * @param int $limit 1 or more
     */
    public function count(BackofficeOrderFilter $filter, int $limit = 10_000): BoundedCount;
}
