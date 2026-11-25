<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\StaffTransition;

/**
 * A staff member changes the status of an order they saw at expectedVersion, with an optional note (required to
 * cancel and to register a return) and, for a shipment, an optional tracking number as staff typed it (the other
 * transitions take none). The staff member is the `sub` and the name of the token of the `backoffice` realm.
 */
final readonly class ChangeOrderStatus
{
    public function __construct(
        public string $orderId,
        public StaffTransition $transition,
        public int $expectedVersion,
        public ?string $note,
        public string $staffId,
        public string $staffUsername,
        public ?string $trackingNumber = null,
    ) {
    }
}
