<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

use App\Payment\Application\PaymentAccess;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderToPay;

/**
 * The orders of Ordering a caller may pay or see the payment of.
 */
interface PayableOrders
{
    /**
     * @return OrderToPay|null null for every case without access (unknown order, wrong token, somebody else's order,
     *                         an order of the other kind of owner)
     */
    public function find(OrderId $orderId, PaymentAccess $access): ?OrderToPay;
}
