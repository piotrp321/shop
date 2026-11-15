<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Shared\Domain\BoundedCount;

/**
 * The queues of the dashboard, each counted up to 1000 by the module that owns it; null where the staff member has no
 * access to the queue.
 */
final readonly class QueuesSection
{
    public function __construct(
        public ?BoundedCount $paidToShip,
        public ?BoundedCount $awaitingPayment,
        public ?BoundedCount $pendingRefunds,
        public ?BoundedCount $overdueRefunds,
        public ?BoundedCount $outOfStockVariants,
        public ?BoundedCount $lowStockVariants,
        public ?BoundedCount $productsWithoutImages,
    ) {
    }
}
