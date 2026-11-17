<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

/**
 * Which queues of the dashboard the staff member may see, each decided by the voter of the module that owns the queue
 * (the caller asks them). A queue without access is not counted and is null in the answer.
 */
final readonly class QueueAccess
{
    /**
     * @param bool $viewOrders          the paid orders to ship (VIEW_ORDERS)
     * @param bool $viewPendingPayments the orders awaiting payment (VIEW_PENDING_PAYMENTS)
     * @param bool $completeRefunds     the pending and the overdue refunds (COMPLETE_REFUND)
     * @param bool $manageStock         the variants out of stock and with a low stock (MANAGE_STOCK)
     * @param bool $manageProducts      the products without images (MANAGE_PRODUCTS)
     */
    public function __construct(
        public bool $viewOrders,
        public bool $viewPendingPayments,
        public bool $completeRefunds,
        public bool $manageStock,
        public bool $manageProducts,
    ) {
    }

    public static function none(): self
    {
        return new self(false, false, false, false, false);
    }
}
