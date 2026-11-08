<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Query\BackofficeOrderCursor;

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
}
