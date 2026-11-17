<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Port;

use App\Shared\Domain\BoundedCount;

/**
 * The queues of the orders as Ordering counts them; null for a queue that was not asked for.
 */
final readonly class OrderQueueCounts
{
    public function __construct(
        public ?BoundedCount $paidToShip,
        public ?BoundedCount $awaitingPayment,
        public ?BoundedCount $pendingRefunds,
        public ?BoundedCount $overdueRefunds,
    ) {
    }
}
