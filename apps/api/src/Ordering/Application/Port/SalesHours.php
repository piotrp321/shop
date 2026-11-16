<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\OrderId;

/**
 * The sales of every hour (in UTC, so a whole hour of the Polish clock too): the number and the gross total of the
 * orders paid in it, by paid_at. The pre-aggregation the dashboard sums, never the orders themselves.
 */
interface SalesHours
{
    /**
     * Adds a paid order to the hour of its payment, in the transaction of the caller (MarkOrderPaid): a rolled-back
     * payment rolls the addition back with it.
     *
     * @param int $gross the gross total of the order, in grosze
     */
    public function add(OrderId $orderId, \DateTimeImmutable $paidAt, int $gross): void;

    /**
     * The orders paid from $from (inclusive) to $to (exclusive), both the start of an hour.
     */
    public function totals(\DateTimeImmutable $from, \DateTimeImmutable $to): SalesTotals;
}
