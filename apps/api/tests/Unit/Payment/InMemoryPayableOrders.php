<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Application\OrderPayabilities;
use App\Payment\Application\OrderPayability;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\Port\PayableOrders;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderToPay;

/**
 * Orders as Ordering's GetOrderForPayment gives them: only to the guest with the token or to the owning customer,
 * null otherwise; and their payability as GetOrderPayability gives it (an order without one is unknown to Ordering).
 */
final class InMemoryPayableOrders implements PayableOrders
{
    /** @var array<string, array{OrderToPay, PaymentAccess}> */
    private array $orders = [];
    public int $asked = 0;
    /** What a concurrent transaction commits right after a read (e.g. a webhook that pays the order). */
    public ?\Closure $afterRead = null;
    /** @var array<string, OrderPayability> by order id */
    public array $payability = [];
    /** @var array<string, string> the reason by id of an order that exists but Ordering could not read */
    public array $unreadable = [];
    /** @var list<list<string>> the order ids of every question about the payability */
    public array $payabilityAsked = [];

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

    public function payabilityOf(array $orderIds): OrderPayabilities
    {
        $ids = array_map(static fn (OrderId $id): string => $id->toString(), $orderIds);
        $this->payabilityAsked[] = $ids;

        return new OrderPayabilities(array_intersect_key($this->payability, array_flip($ids)), array_intersect_key($this->unreadable, array_flip($ids)));
    }
}
