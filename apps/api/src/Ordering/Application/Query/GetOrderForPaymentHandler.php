<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\OrderStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The order for a payment, or null for every case without access: an unknown order, an id that is no UUID, a wrong
 * token, somebody else's order or an order of the other kind of owner. One answer, so the caller cannot tell them
 * apart.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetOrderForPaymentHandler
{
    public function __construct(
        private OrderRepository $orders,
        private Clock $clock,
    ) {
    }

    public function __invoke(GetOrderForPayment $query): ?OrderForPayment
    {
        try {
            $order = $this->orders->get(OrderId::fromString($query->orderId));
        } catch (\InvalidArgumentException|OrderNotFound) {
            return null;
        }
        if (!$order->grantsAccess($query->access->claim())) {
            return null;
        }
        $gross = $order->totals()->gross();

        return new OrderForPayment(
            $order->id()->toString(),
            $order->number()->toString(),
            self::statusForPayment($order->status()),
            $gross->amount(),
            $gross->currency()->value,
            $order->paymentDeadline(),
            $order->isPayable($this->clock->now()),
        );
    }

    /**
     * @return 'new'|'paid'|'cancelled'
     */
    private static function statusForPayment(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::New => 'new',
            OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Returned => 'paid',
            OrderStatus::Cancelled => 'cancelled',
        };
    }
}
