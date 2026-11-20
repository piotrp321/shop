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
 * or an id that is no UUID is left out of the answer. An order that exists but cannot be read (its stored data breaks
 * an invariant of the domain, e.g. one made stricter later: a logic error of the domain) is no unknown order: it is
 * reported apart, so Payment does not take it for an order that can no longer be paid. A failure of the database is
 * thrown. The orders are read one by one through the repository, so the domain decides what is payable; a batch has
 * at most GetOrderPayability::MAX_ORDERS, and its caller runs only for the pending payments, which are few.
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
     * @throws \InvalidArgumentException more than GetOrderPayability::MAX_ORDERS ids
     */
    public function __invoke(GetOrderPayability $query): OrderPayabilities
    {
        if (\count($query->orderIds) > GetOrderPayability::MAX_ORDERS) {
            throw new \InvalidArgumentException(\sprintf('At most %d orders in one query.', GetOrderPayability::MAX_ORDERS));
        }

        $now = $this->clock->now();
        $orders = [];
        $unreadable = [];
        foreach ($query->orderIds as $orderId) {
            try {
                $id = OrderId::fromString($orderId);
            } catch (\InvalidArgumentException) {
                continue;
            }
            try {
                $order = $this->orders->get($id);
            } catch (OrderNotFound) {
                continue;
            } catch (\Throwable $failure) {
                // Only the errors of the reconstitution (of the domain: InvalidArgumentException, InvalidOrder and
                // DomainException are LogicException; of its enums: ValueError) make the order unreadable; the errors
                // of the database are none of them, and are thrown.
                if (!$failure instanceof \LogicException && !$failure instanceof \ValueError) {
                    throw $failure;
                }
                $unreadable[$id->toString()] = $failure::class.': '.$failure->getMessage();
                continue;
            }
            $orders[] = new OrderPayability($order->id()->toString(), $order->isPayable($now), GetOrderForPaymentHandler::statusForPayment($order->status()));
        }

        return new OrderPayabilities($orders, $unreadable);
    }
}
