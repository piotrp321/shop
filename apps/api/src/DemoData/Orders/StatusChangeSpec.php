<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A row of ordering_order_status_change: a change of the status with its moment and actor (`customer`, `system` or
 * `staff` with the id and the username of the staff member).
 */
final readonly class StatusChangeSpec
{
    public function __construct(
        public int $position,
        public ?string $from,
        public string $to,
        public \DateTimeImmutable $occurredAt,
        public string $actorType,
        public ?string $staffId,
        public ?string $staffUsername,
    ) {
    }
}
