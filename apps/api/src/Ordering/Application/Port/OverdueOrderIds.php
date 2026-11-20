<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * The ids of the new orders whose deadline has passed, read without a lock (each one is locked by its own expiry).
 */
interface OverdueOrderIds
{
    /**
     * At most $limit new orders whose deadline is earlier than $deadlineBefore, oldest first by (placed_at, id), after
     * the order $after (exclusive) when given.
     *
     * @return list<OverdueOrder>
     */
    public function page(\DateTimeImmutable $deadlineBefore, ?OverdueOrder $after, int $limit): array;
}
