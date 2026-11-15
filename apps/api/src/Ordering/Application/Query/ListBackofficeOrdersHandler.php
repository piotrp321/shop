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
    /** An order number as staff type it: R- and digits, in any letter case (P9). */
    private const string NUMBER = '/^[Rr]-[0-9]+$/D';

    public function __construct(
        private BackofficeOrderReader $orders,
        private Clock $clock,
    ) {
    }

    /**
     * The search term, trimmed, is an exact number when it looks like one, otherwise an exact email when it has an @;
     * anything else finds nothing, without a read. One order more than the page is read: its presence is what tells
     * there is a next page. The first page (no cursor) also carries the bounded total of the filter: the number of
     * its rows when there is no next page (nothing to count), otherwise the reader counts, never the table whole.
     *
     * @throws InvalidBackofficeOrderCursor
     * @throws InvalidBackofficeOrderList   a sort with the pending refunds, the amount with a search
     */
    public function __invoke(ListBackofficeOrders $query): BackofficeOrderList
    {
        $sort = self::sortOf($query);
        $after = null === $query->after ? null : BackofficeOrderCursor::decode($query->after, $sort);

        $number = null;
        $email = null;
        if (null !== $query->q) {
            $term = trim($query->q);
            if (1 === preg_match(self::NUMBER, $term)) {
                $number = 'R-'.substr($term, 2);
            } elseif (str_contains($term, '@') && !str_contains($term, "\0")) {
                // The database driver cuts a parameter at a NUL, which would silently search for something else.
                $email = $term;
            } else {
                return new BackofficeOrderList([], null, null === $after ? new BoundedCount(0, false) : null);
            }
        }

        $filter = new BackofficeOrderFilter($query->status, $query->pendingRefunds, $number, $email, $sort);
        $orders = $this->orders->page($filter, $after, $query->perPage + 1);
        $next = null;
        if (\count($orders) > $query->perPage) {
            $orders = \array_slice($orders, 0, $query->perPage);
            $next = BackofficeOrderCursor::after($orders[$query->perPage - 1], $sort)->encode();
            $total = null === $after ? $this->orders->count($filter) : null;
        } else {
            $total = null === $after ? new BoundedCount(\count($orders), false) : null;
        }
        $now = $this->clock->now();

        return new BackofficeOrderList(array_map(static fn (ListedOrder $order): BackofficeOrderListItem => self::item($order, $now), $orders), $next, $total);
    }

    /**
     * @throws InvalidBackofficeOrderList
     */
    private static function sortOf(ListBackofficeOrders $query): BackofficeOrderSort
    {
        if ($query->pendingRefunds) {
            return null === $query->sort ? BackofficeOrderSort::RefundDueDate : throw InvalidBackofficeOrderList::sortWithPendingRefunds();
        }
        if (BackofficeOrderSort::RefundDueDate === $query->sort) {
            throw InvalidBackofficeOrderList::dueDateAsASort();
        }
        $sort = $query->sort ?? BackofficeOrderSort::PlacedDescending;
        // No index serves the amount together with the search: the list would read every order of the search first.
        if ($sort->byTotal() && null !== $query->q) {
            throw InvalidBackofficeOrderList::totalWithSearch();
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
