<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;

/**
 * An order as its customer sees it in the history: the lines and totals, the address and the moments of the statuses.
 * No history of changes, notes, data of staff, refund or payment. Amounts are in grosze.
 */
final readonly class CustomerOrderView
{
    /**
     * @param list<OrderLineView> $lines
     */
    public function __construct(
        public string $orderId,
        public string $number,
        public string $status,
        public ShippingAddressView $shippingAddress,
        public array $lines,
        public ?OrderDiscountView $discount,
        public OrderTotalsView $totals,
        public \DateTimeImmutable $placedAt,
        public \DateTimeImmutable $paymentDeadline,
        public bool $paymentDeadlinePassed,
        public ?\DateTimeImmutable $paidAt,
        public ?\DateTimeImmutable $shippedAt,
        public ?\DateTimeImmutable $deliveredAt,
        public ?\DateTimeImmutable $cancelledAt,
        public ?\DateTimeImmutable $returnedAt,
    ) {
    }

    /**
     * @param \DateTimeImmutable $now the moment the payment deadline is checked against
     */
    public static function of(Order $order, \DateTimeImmutable $now): self
    {
        $base = OrderView::of($order);

        return new self(
            $base->orderId,
            $base->number,
            $base->status,
            $base->shippingAddress,
            $base->lines,
            $base->discount,
            $base->totals,
            $order->placedAt(),
            $order->paymentDeadline(),
            // The rule of Order::isPayable() for a new order: no payment can be started from the deadline on.
            OrderStatus::New === $order->status() && $now >= $order->paymentDeadline(),
            $order->paidAt(),
            $order->shippedAt(),
            $order->deliveredAt(),
            $order->cancelledAt(),
            $order->returnedAt(),
        );
    }
}
