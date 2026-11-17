<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * Rebuilds the hourly sales from the orders, one hour at a time, correct while payments add to the same hours: each
 * hour creates its 16 rows when missing and locks them before it counts the orders paid in it, and writes them in the
 * same transaction (spec ADMIN-FEATURES, „Wdrożenie”). A payment that has not committed when the hour is counted adds
 * itself after the rebuild of the hour (it waits for the lock); one that committed before is counted.
 */
interface SalesHoursRebuild
{
    /**
     * The first hour (its start, UTC) from $from on (inclusive) with a paid order or with rows of sales; from the
     * very first one without $from. Null when there is none.
     */
    public function nextHour(?\DateTimeImmutable $from): ?\DateTimeImmutable;

    /**
     * Rewrites the rows of the hour from the orders paid in it, in a transaction of its own: one row per shard with
     * orders, no row for a shard without (so the rows are exactly those the payments would have written).
     *
     * @return SalesTotals the orders of the hour
     */
    public function rebuild(\DateTimeImmutable $hour): SalesTotals;
}
