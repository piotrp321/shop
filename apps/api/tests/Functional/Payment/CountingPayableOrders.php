<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Payment\Application\OrderPayabilities;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\Port\PayableOrders;
use App\Payment\Domain\OrderId;

/**
 * The adapter of payment:close-abandoned that counts the orders of every question about the payability.
 */
final class CountingPayableOrders implements PayableOrders
{
    /** @var list<int> */
    public array $asked = [];

    public function __construct(private readonly PayableOrders $orders)
    {
    }

    public function find(OrderId $orderId, PaymentAccess $access): never
    {
        throw new \LogicException('payment:close-abandoned never reads an order with an access.');
    }

    public function payabilityOf(array $orderIds): OrderPayabilities
    {
        $this->asked[] = \count($orderIds);

        return $this->orders->payabilityOf($orderIds);
    }
}
