<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\Query\BackofficeOrderCursor;

/**
 * Reads the orders of one customer for the history in the store, without locks. Read-only. Orders of guests and of
 * other customers are never read.
 */
interface CustomerOrderReader
{
    /**
     * The orders placed by the customer (the `sub` of the token), newest first (placed_at, then id, descending),
     * strictly after the cursor.
     *
     * @param BackofficeOrderCursor|null $after of the sort by placement
     * @param int                        $limit 1 or more
     *
     * @return list<CustomerListedOrder> at most $limit
     */
    public function page(string $customerId, ?BackofficeOrderCursor $after, int $limit): array;
}
