<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

/**
 * The queues of the orders of the dashboard, from the module that owns the orders.
 */
interface OrderQueues
{
    /**
     * Only the queues asked for are counted; the others are null.
     *
     * @param bool $refunds the pending and the overdue refunds
     */
    public function counts(bool $paidToShip, bool $awaitingPayment, bool $refunds): OrderQueueCounts;
}
