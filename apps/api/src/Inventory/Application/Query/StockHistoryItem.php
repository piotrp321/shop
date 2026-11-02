<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * One recorded stock change as the panel shows it, with the id that places it in the paging order. The deltas and
 * the state after the change are in whole units. The id of the actor is never part of it.
 */
final readonly class StockHistoryItem
{
    /**
     * @param string      $id        the id of the history entry (UUID); within one second the later entry has the greater id
     * @param string      $type      `receipt`, `correction`, `reservation`, `release` or `commit`
     * @param string|null $reason    the reason of a correction, null for every other type
     * @param string|null $reference the receipt key or the reservation key
     * @param string      $source    `staff`, `seed` or `system`
     * @param string|null $actorName the staff member of a `staff` change, null for the other sources
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $occurredAt,
        public string $type,
        public int $physicalDelta,
        public int $reservedDelta,
        public int $physicalAfter,
        public int $reservedAfter,
        public ?string $reason,
        public ?string $note,
        public ?string $reference,
        public string $source,
        public ?string $actorName,
    ) {
    }
}
