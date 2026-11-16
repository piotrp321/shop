<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

/**
 * The queues of the orders for the dashboard. The caller decides which ones the staff member may see (by the voter
 * of this module); a queue not asked for is not counted.
 */
final readonly class GetOrderQueues
{
    /**
     * @param bool $paidToShip      the paid orders, waiting for shipping
     * @param bool $awaitingPayment the new orders, not paid yet
     * @param bool $pendingRefunds  the refunds that are not completed
     * @param bool $overdueRefunds  the pending refunds past their due date
     */
    public function __construct(
        public bool $paidToShip,
        public bool $awaitingPayment,
        public bool $pendingRefunds,
        public bool $overdueRefunds,
    ) {
    }
}
