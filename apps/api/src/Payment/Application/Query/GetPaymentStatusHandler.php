<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

use App\Payment\Application\OrderNotFound;
use App\Payment\Application\Port\PayableOrders;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The order (with the same access rule as StartPayment) and its newest payment, read without a lock.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetPaymentStatusHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private PayableOrders $orders,
    ) {
    }

    /**
     * @throws OrderNotFound
     */
    public function __invoke(GetPaymentStatus $query): PaymentStatusView
    {
        try {
            $orderId = OrderId::fromString($query->orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFound();
        }
        $order = $this->orders->find($orderId, $query->access) ?? throw new OrderNotFound();
        $latest = $this->payments->latestOfOrder($orderId);

        return new PaymentStatusView(
            $order->number,
            $order->state->value,
            $order->paymentDeadline,
            $order->totalGross,
            $order->currency->value,
            $order->payable,
            null === $latest ? null : new LatestPaymentView($latest->provider()->value, $latest->status()->value, $latest->resumeUrl($order->payable)),
        );
    }
}
