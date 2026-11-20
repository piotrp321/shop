<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\OverdueOrderIds;
use App\Ordering\Domain\Order;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The candidates of ordering:expire-overdue (docs/features/async-reliability/spec.md, W5): the new orders whose deadline
 * is earlier than now less the window of the webhooks (Order::DELIVERY_WINDOW) and the grace. The grace leaves the usual
 * case to the delayed ExpireUnpaidOrder, which comes at the end of the window; the command only catches what it missed
 * (a message in `failed`, a lost one, a worker that stands).
 */
final readonly class OverdueOrders
{
    public function __construct(
        private OverdueOrderIds $ids,
        private Clock $clock,
        #[Autowire(param: 'app.ordering.expiry_grace_seconds')]
        private int $graceSeconds,
    ) {
    }

    /**
     * The ids in batches of at most $batchSize by the keyset (placed_at, id), until a batch is empty. The boundary is
     * fixed when the walk starts, so the walk ends however long the batches take.
     *
     * @return \Generator<int, list<string>>
     */
    public function batches(int $batchSize): \Generator
    {
        $deadlineBefore = $this->clock->now()->sub(\DateInterval::createFromDateString(Order::DELIVERY_WINDOW))->modify(\sprintf('-%d seconds', $this->graceSeconds));
        $after = null;
        while ([] !== $page = $this->ids->page($deadlineBefore, $after, $batchSize)) {
            yield array_map(static fn ($order): string => $order->orderId, $page);
            $after = $page[\count($page) - 1];
        }
    }
}
