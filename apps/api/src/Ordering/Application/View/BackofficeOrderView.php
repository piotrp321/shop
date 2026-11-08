<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatusChange;
use App\Ordering\Domain\StaffTransition;

/**
 * An order as the panel shows it: the contact, the address, the lines and totals, the moments of the statuses, the
 * payment, the version the next change of staff refers to, the history (oldest first), the refund and the transitions
 * the status allows (statusTransitions, OrderStatus::allowedStaffTransitions(); which of them the staff member may
 * make is for the voter). Amounts are in grosze.
 */
final readonly class BackofficeOrderView
{
    /**
     * @param list<OrderLineView>    $lines
     * @param list<StatusChangeView> $history
     * @param list<string>           $statusTransitions values of StaffTransition
     */
    public function __construct(
        public string $orderId,
        public string $number,
        public string $status,
        public int $version,
        public string $email,
        public string $fullName,
        public string $phone,
        public ShippingAddressView $shippingAddress,
        public array $lines,
        public ?OrderDiscountView $discount,
        public OrderTotalsView $totals,
        public \DateTimeImmutable $placedAt,
        public \DateTimeImmutable $paymentDeadline,
        public ?\DateTimeImmutable $paidAt,
        public ?string $paymentId,
        public ?\DateTimeImmutable $shippedAt,
        public ?\DateTimeImmutable $deliveredAt,
        public ?\DateTimeImmutable $cancelledAt,
        public ?string $cancellationReason,
        public ?\DateTimeImmutable $returnedAt,
        public array $history,
        public ?RefundView $refund,
        public array $statusTransitions,
    ) {
    }

    /**
     * @param \DateTimeImmutable $now the moment the refund is overdue against
     */
    public static function of(Order $order, \DateTimeImmutable $now): self
    {
        $base = OrderView::of($order);
        $refund = $order->refund();

        return new self(
            $base->orderId,
            $base->number,
            $base->status,
            $order->version(),
            $base->email,
            $base->fullName,
            $base->phone,
            $base->shippingAddress,
            $base->lines,
            $base->discount,
            $base->totals,
            $order->placedAt(),
            $order->paymentDeadline(),
            $order->paidAt(),
            $order->paymentId()?->toString(),
            $order->shippedAt(),
            $order->deliveredAt(),
            $order->cancelledAt(),
            $order->cancellationReason()?->value,
            $order->returnedAt(),
            array_map(
                static fn (OrderStatusChange $change): StatusChangeView => new StatusChangeView(
                    $change->from()?->value,
                    $change->to()->value,
                    $change->occurredAt(),
                    $change->actor()->type()->value,
                    $change->actor()->staffMember()?->staffId(),
                    $change->actor()->staffMember()?->username(),
                    $change->note(),
                ),
                $order->history(),
            ),
            null === $refund ? null : new RefundView(
                $refund->amount()->amount(),
                $refund->amount()->currency()->value,
                $refund->dueAt(),
                $refund->isOverdue($now),
                $refund->completedAt(),
                $refund->completedBy()?->staffId(),
                $refund->completedBy()?->username(),
                $refund->note(),
            ),
            array_map(static fn (StaffTransition $transition): string => $transition->value, $order->status()->allowedStaffTransitions()),
        );
    }
}
