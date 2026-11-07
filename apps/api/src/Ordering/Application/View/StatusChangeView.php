<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * An entry of the history of the statuses of an order for the panel. The actor is `system`, `customer` or `staff`;
 * only `staff` has the id and the name of the staff member.
 */
final readonly class StatusChangeView
{
    /**
     * @param ?string $from null for the placement
     */
    public function __construct(
        public ?string $from,
        public string $to,
        public \DateTimeImmutable $occurredAt,
        public string $actorType,
        public ?string $staffId,
        public ?string $staffUsername,
        public ?string $note,
    ) {
    }
}
