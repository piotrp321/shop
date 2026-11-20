<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Application\OrderPayabilities;
use App\Payment\Application\PaymentAccess;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderToPay;

/**
 * The orders of Ordering a caller may pay or see the payment of, and whether orders can still be paid at all.
 */
interface PayableOrders
{
    /**
     * @return OrderToPay|null null for every case without access (unknown order, wrong token, somebody else's order,
     *                         an order of the other kind of owner)
     */
    public function find(OrderId $orderId, PaymentAccess $access): ?OrderToPay;

    /**
     * Whether each order can still be paid, for payment:close-abandoned: one question for up to 100 orders, without
     * access (the answer holds nothing else of an order).
     *
     * @param list<OrderId> $orderIds at most 100
     *
     * @return OrderPayabilities an order Ordering does not know is left out; one it could not read is apart
     */
    public function payabilityOf(array $orderIds): OrderPayabilities;
}
