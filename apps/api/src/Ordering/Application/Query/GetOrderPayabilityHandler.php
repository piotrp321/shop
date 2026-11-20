<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The payability of the orders in the order of the ids, read as committed (the orders are read anew). An unknown order
 * or an id that is no UUID is left out of the answer. The orders are read one by one through the repository, so the
 * domain decides what is payable; a batch has at most GetOrderPayability::MAX_ORDERS, and its caller runs only for the
 * pending payments, which are few.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetOrderPayabilityHandler
{
    public function __construct(
        private OrderRepository $orders,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<OrderPayability>
     *
     * @throws \InvalidArgumentException more than GetOrderPayability::MAX_ORDERS ids
     */
    public function __invoke(GetOrderPayability $query): array
    {
        if (\count($query->orderIds) > GetOrderPayability::MAX_ORDERS) {
            throw new \InvalidArgumentException(\sprintf('At most %d orders in one query.', GetOrderPayability::MAX_ORDERS));
        }

        $now = $this->clock->now();
        $answer = [];
        foreach ($query->orderIds as $orderId) {
            try {
                $order = $this->orders->get(OrderId::fromString($orderId));
            } catch (\InvalidArgumentException|OrderNotFound) {
                continue;
            }
            $answer[] = new OrderPayability($order->id()->toString(), $order->isPayable($now), GetOrderForPaymentHandler::statusForPayment($order->status()));
        }

        return $answer;
    }
}
