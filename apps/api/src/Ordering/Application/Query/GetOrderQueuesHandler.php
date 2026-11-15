<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\BackofficeOrderFilter;
use App\Ordering\Application\Port\BackofficeOrderReader;
use App\Ordering\Application\Port\Clock;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\BoundedCount;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every queue is the count of the filter of the order list it links to (the status, the pending and the overdue
 * refunds), so the number and the list agree, on the same indexes as the list.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetOrderQueuesHandler
{
    /** The dashboard counts up to this many; more is shown as "1000+". */
    public const int LIMIT = 1000;

    public function __construct(
        private BackofficeOrderReader $orders,
        private Clock $clock,
    ) {
    }

    public function __invoke(GetOrderQueues $query): OrderQueues
    {
        return new OrderQueues(
            $query->paidToShip ? $this->count(new BackofficeOrderFilter(OrderStatus::Paid, false, null, null)) : null,
            $query->awaitingPayment ? $this->count(new BackofficeOrderFilter(OrderStatus::New, false, null, null)) : null,
            $query->pendingRefunds ? $this->count(new BackofficeOrderFilter(null, true, null, null)) : null,
            $query->overdueRefunds ? $this->count(new BackofficeOrderFilter(null, true, null, null, null, null, null, null, $this->clock->now())) : null,
        );
    }

    private function count(BackofficeOrderFilter $filter): BoundedCount
    {
        return $this->orders->count($filter, self::LIMIT);
    }
}
