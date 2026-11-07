<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the order as stored, without a lock, and gives the same view the commands of staff answer with
 * (BackofficeOrderView), the refund overdue against the clock. Which of the transitions of its status the staff
 * member may make is for the voter, in the UI.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetBackofficeOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private Clock $clock,
    ) {
    }

    /**
     * @throws OrderNotFound also for an id that is no UUID
     */
    public function __invoke(GetBackofficeOrder $query): BackofficeOrderView
    {
        try {
            $id = OrderId::fromString($query->orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFound();
        }

        return BackofficeOrderView::of($this->orders->get($id), $this->clock->now());
    }
}
