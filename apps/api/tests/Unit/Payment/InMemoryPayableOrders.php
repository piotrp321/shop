<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Application\PaymentAccess;
use App\Payment\Application\Port\PayableOrders;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderToPay;

/**
 * Orders as Ordering's GetOrderForPayment gives them: only to the guest with the token or to the owning customer,
 * null otherwise.
 */
final class InMemoryPayableOrders implements PayableOrders
{
    /** @var array<string, array{OrderToPay, PaymentAccess}> */
    private array $orders = [];
    public int $asked = 0;
    /** What a concurrent transaction commits right after a read (e.g. a webhook that pays the order). */
    public ?\Closure $afterRead = null;

    public function add(OrderToPay $order, PaymentAccess $access): void
    {
        $this->orders[$order->orderId->toString()] = [$order, $access];
    }

    public function find(OrderId $orderId, PaymentAccess $access): ?OrderToPay
    {
        ++$this->asked;
        [$order, $granted] = $this->orders[$orderId->toString()] ?? [null, null];
        $found = null !== $granted && $granted->token === $access->token && $granted->customerId === $access->customerId ? $order : null;
        if (null !== $this->afterRead) {
            ($this->afterRead)();
        }

        return $found;
    }
}
