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
     * The orders that match the filter, strictly after the cursor: newest first (placed_at, then id, descending), or,
     * for the pending refunds, the nearest due date first (refund_due_at, then id, ascending).
     *
     * @param BackofficeOrderCursor|null $after of the same sort as the filter
     * @param int                        $limit 1 or more
     *
     * @return list<ListedOrder> at most $limit
     */
    public function page(BackofficeOrderFilter $filter, ?BackofficeOrderCursor $after, int $limit): array;

    /**
     * How many orders match the filter, whatever the cursor of a page: counted up to 10 000 (at most 10 001 rows are
     * read), never the whole table.
     */
    public function count(BackofficeOrderFilter $filter): BoundedCount;
}
