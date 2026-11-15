<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\BackofficeOrderReader;
use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\ListedOrder;
use App\Shared\Domain\BoundedCount;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListBackofficeOrdersHandler
{
    public function __construct(
        private BackofficeOrderReader $orders,
        private Clock $clock,
    ) {
    }

    /**
     * The search term is an exact number, a fragment of the number, the email and the name, or an exact email too long
     * for a fragment (see OrderSearchTerm); anything else is refused. One order more than the page is read: its
     * presence is what tells there is a next page. The first page (no cursor) also carries the bounded total of the
     * filter: the number of its rows when there is no next page (nothing to count), otherwise the reader counts,
     * never the table whole.
     *
     * @throws InvalidBackofficeOrderCursor
     *                                      The overdue refunds are the pending ones due before the moment of the read, in the same order and with the same
     *                                      cursor
     * @throws InvalidBackofficeOrderList   a sort with the pending refunds, the amount with a search or a range of days,
     *                                      the pending refunds with a range of days, a search term or a day that is
     *                                      refused, a range that ends before it starts
     */
    public function __invoke(ListBackofficeOrders $query): BackofficeOrderList
    {
        $now = $this->clock->now();
        $pendingRefunds = $query->pendingRefunds || $query->overdueRefunds;
        $sort = self::sortOf($query, $pendingRefunds);
        $after = null === $query->after ? null : BackofficeOrderCursor::decode($query->after, $sort);
        $term = null === $query->q ? null : OrderSearchTerm::parse($query->q);
        $range = OrderPlacedRange::fromDays($query->placedFrom, $query->placedTo);
        if ($pendingRefunds && !$range->isEmpty()) {
            throw InvalidBackofficeOrderList::pendingRefundsWithDateRange();
        }

        $filter = new BackofficeOrderFilter($query->status, $pendingRefunds, $term?->number, $term?->email, $sort, $term?->fragment, $range->from, $range->before, $query->overdueRefunds ? $now : null);
        $orders = $this->orders->page($filter, $after, $query->perPage + 1);
        $next = null;
        if (\count($orders) > $query->perPage) {
            $orders = \array_slice($orders, 0, $query->perPage);
            $next = BackofficeOrderCursor::after($orders[$query->perPage - 1], $sort)->encode();
            $total = null === $after ? $this->orders->count($filter) : null;
        } else {
            $total = null === $after ? new BoundedCount(\count($orders), false) : null;
        }

        return new BackofficeOrderList(array_map(static fn (ListedOrder $order): BackofficeOrderListItem => self::item($order, $now), $orders), $next, $total);
    }

    /**
     * @throws InvalidBackofficeOrderList
     */
    private static function sortOf(ListBackofficeOrders $query, bool $pendingRefunds): BackofficeOrderSort
    {
        if ($pendingRefunds) {
            return null === $query->sort ? BackofficeOrderSort::RefundDueDate : throw InvalidBackofficeOrderList::sortWithPendingRefunds();
        }
        if (BackofficeOrderSort::RefundDueDate === $query->sort) {
            throw InvalidBackofficeOrderList::dueDateAsASort();
        }
        $sort = $query->sort ?? BackofficeOrderSort::PlacedDescending;
        // No index serves the amount together with the search or the range of days: the list would read every order of them first.
        if ($sort->byTotal()) {
            if (null !== $query->q) {
                throw InvalidBackofficeOrderList::totalWithSearch();
            }
            if (null !== $query->placedFrom || null !== $query->placedTo) {
                throw InvalidBackofficeOrderList::totalWithDateRange();
            }
        }

        return $sort;
    }

    private static function item(ListedOrder $order, \DateTimeImmutable $now): BackofficeOrderListItem
    {
        $refund = null;
        if (null !== $order->refundAmount && null !== $order->refundDueAt) {
            // The rule of Refund::isOverdue(): pending past the due date.
            $refund = new BackofficeOrderListRefund(
                $order->refundAmount,
                $order->refundDueAt,
                $order->refundCompletedAt,
                null === $order->refundCompletedAt && $now > $order->refundDueAt,
            );
        }

        return new BackofficeOrderListItem(
            $order->orderId,
            $order->number,
            $order->placedAt,
            $order->status,
            $order->email,
            $order->fullName,
            $order->totalGross,
            $order->currency,
            $order->itemCount,
            $refund,
        );
    }
}
