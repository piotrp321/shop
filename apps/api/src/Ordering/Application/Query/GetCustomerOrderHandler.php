<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\View\CustomerOrderView;
use App\Ordering\Domain\AccessClaim;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the order as stored, without a lock. An unknown order, an order of another customer, a guest order and an id
 * that is no UUID give the same answer (null), so the caller cannot tell them apart.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCustomerOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private Clock $clock,
    ) {
    }

    public function __invoke(GetCustomerOrder $query): ?CustomerOrderView
    {
        try {
            $order = $this->orders->get(OrderId::fromString($query->orderId));
        } catch (\InvalidArgumentException|OrderNotFound) {
            return null;
        }
        if ('' === $query->customerId || !$order->grantsAccess(AccessClaim::customer($query->customerId))) {
            return null;
        }

        return CustomerOrderView::of($order, $this->clock->now());
    }
}
