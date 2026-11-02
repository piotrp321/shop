<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of the stock history. Read-only: rows are inserted and never updated or deleted (only the demo reset
 * empties the table).
 *
 * The migration makes the first index (product_id, occurred_at DESC, id) for the newest-first history of a product;
 * the mapping cannot express the direction. The partial unique index keeps a receipt key to one receipt.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'inventory_stock_movement')]
#[ORM\Index(name: 'inventory_stock_movement_product_occurred_at_idx', columns: ['product_id', 'occurred_at', 'id'])]
// The predicate as PostgreSQL gives it back (pg_get_expr), so the schema comparison sees no difference.
#[ORM\UniqueConstraint(name: self::RECEIPT_REFERENCE_INDEX, columns: ['reference'], options: ['where' => "((type)::text = 'receipt'::text)"])]
class StockMovementRecord
{
    public const string RECEIPT_REFERENCE_INDEX = 'inventory_stock_movement_receipt_reference_key';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
        #[ORM\Column(type: 'guid')]
        public readonly string $productId,
        #[ORM\Column(length: 16)]
        public readonly string $type,
        #[ORM\Column]
        public readonly int $physicalDelta,
        #[ORM\Column]
        public readonly int $reservedDelta,
        #[ORM\Column]
        public readonly int $physicalAfter,
        #[ORM\Column]
        public readonly int $reservedAfter,
        #[ORM\Column(length: 32, nullable: true)]
        public readonly ?string $reason,
        #[ORM\Column(length: 500, nullable: true)]
        public readonly ?string $note,
        #[ORM\Column(length: 100, nullable: true)]
        public readonly ?string $reference,
        #[ORM\Column(length: 16)]
        public readonly string $source,
        #[ORM\Column(nullable: true)]
        public readonly ?string $actorId,
        #[ORM\Column(nullable: true)]
        public readonly ?string $actorName,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }
}
