<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\StockHistoryItem;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'One recorded stock change: what changed and the stock right after it, in whole units.', required: ['occurredAt', 'type', 'physicalDelta', 'reservedDelta', 'physicalAfter', 'reservedAfter', 'reason', 'note', 'reference', 'source', 'actorName'])]
final readonly class StockHistoryItemResponse
{
    public function __construct(
        #[OA\Property(description: 'When the stock changed (RFC 3339, UTC).', example: '2026-11-01T12:30:00+00:00')]
        public string $occurredAt,
        #[OA\Property(description: 'What happened: a `receipt` of delivered units, a `correction` after counting, a `reservation`, the `release` of a reservation or its `commit` (the units leave the shelf).', enum: ['receipt', 'correction', 'reservation', 'release', 'commit'], example: 'receipt')]
        public string $type,
        #[OA\Property(description: 'Change of the physical stock (negative when units left).', example: 12)]
        public int $physicalDelta,
        #[OA\Property(description: 'Change of the reserved stock.', example: 0)]
        public int $reservedDelta,
        #[OA\Property(description: 'Physical stock right after the change.', example: 12)]
        public int $physicalAfter,
        #[OA\Property(description: 'Reserved stock right after the change.', example: 0)]
        public int $reservedAfter,
        #[OA\Property(description: 'Reason of a `correction`, null for the other types.', enum: ['inventory_count', 'damage', 'loss', 'other'], nullable: true, example: 'damage')]
        public ?string $reason,
        #[OA\Property(description: 'Note of the staff member, if any.', nullable: true, example: 'Dostawa od dostawcy')]
        public ?string $note,
        #[OA\Property(description: 'The key of the receipt or of the reservation, if any.', nullable: true, example: 'order-1042')]
        public ?string $reference,
        #[OA\Property(description: 'What made the change: `staff` (a staff member in the panel), `seed` (the demo data) or `system` (another module through the reservation port).', enum: ['staff', 'seed', 'system'], example: 'staff')]
        public string $source,
        #[OA\Property(description: 'The staff member who made a `staff` change, null for the other sources.', nullable: true, example: 'Anna Admin')]
        public ?string $actorName,
    ) {
    }

    public static function of(StockHistoryItem $item): self
    {
        return new self(
            $item->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            $item->type,
            $item->physicalDelta,
            $item->reservedDelta,
            $item->physicalAfter,
            $item->reservedAfter,
            $item->reason,
            $item->note,
            $item->reference,
            $item->source,
            $item->actorName,
        );
    }
}
