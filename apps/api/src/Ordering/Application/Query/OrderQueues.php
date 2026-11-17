<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\BoundedCount;

/**
 * The queues of the orders, each counted up to {@see GetOrderQueuesHandler::LIMIT}; null for a queue that was not asked for.
 */
final readonly class OrderQueues
{
    public function __construct(
        public ?BoundedCount $paidToShip,
        public ?BoundedCount $awaitingPayment,
        public ?BoundedCount $pendingRefunds,
        public ?BoundedCount $overdueRefunds,
    ) {
    }
}
