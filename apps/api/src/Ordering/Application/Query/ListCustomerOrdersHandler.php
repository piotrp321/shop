<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\CustomerListedOrder;
use App\Ordering\Application\Port\CustomerOrderReader;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListCustomerOrdersHandler
{
    public function __construct(
        private CustomerOrderReader $orders,
        private Clock $clock,
    ) {
    }

    /**
     * One order more than the page is read: its presence is the only thing that tells there is a next page, so the
     * history is never counted.
     *
     * @throws InvalidBackofficeOrderCursor
     */
    public function __invoke(ListCustomerOrders $query): CustomerOrderList
    {
        $after = null === $query->after ? null : BackofficeOrderCursor::decode($query->after, false);

        $orders = $this->orders->page($query->customerId, $after, $query->perPage + 1);
        $next = null;
        if (\count($orders) > $query->perPage) {
            $orders = \array_slice($orders, 0, $query->perPage);
            $last = $orders[$query->perPage - 1];
            $next = BackofficeOrderCursor::placedAfter($last->placedAt, $last->orderId)->encode();
        }
        $now = $this->clock->now();

        return new CustomerOrderList(array_map(static fn (CustomerListedOrder $order): CustomerOrderListItem => new CustomerOrderListItem(
            $order->orderId,
            $order->number,
            $order->placedAt,
            $order->status,
            $order->totalGross,
            $order->currency,
            $order->itemCount,
            $order->paymentDeadline,
            // The rule of Order::isPayable() for a new order: no payment can be started from the deadline on.
            'new' === $order->status && $now >= $order->paymentDeadline,
        ), $orders), $next);
    }
}
