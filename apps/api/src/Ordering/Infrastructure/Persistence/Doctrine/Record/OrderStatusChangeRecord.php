<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of the history of the statuses of an order, in its position (0 is the placement). Rows are only added,
 * never changed. The foreign key to the order has no ON DELETE CASCADE, like the lines: orders are kept for ever. The
 * migration adds the CHECK constraints of the actor (`system`, `customer`, `staff`; the staff member exactly for
 * `staff`) and of the position.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_order_status_change')]
// DBAL requires an index with exactly the foreign key column; the primary key (order_id, position) does not fulfil
// it, so it is named here instead of being auto-generated as IDX_*.
#[ORM\Index(name: 'ordering_order_status_change_order_id_idx', columns: ['order_id'])]
class OrderStatusChangeRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: OrderRecord::class, inversedBy: 'statusChanges')]
        #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
        public readonly OrderRecord $order,
        #[ORM\Id]
        #[ORM\Column(type: Types::INTEGER)]
        public readonly int $position,
        #[ORM\Column(length: 20, nullable: true)]
        public readonly ?string $fromStatus,
        #[ORM\Column(length: 20)]
        public readonly string $toStatus,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public readonly \DateTimeImmutable $occurredAt,
        #[ORM\Column(length: 16)]
        public readonly string $actorType,
        #[ORM\Column(length: 255, nullable: true)]
        public readonly ?string $staffId,
        #[ORM\Column(length: 255, nullable: true)]
        public readonly ?string $staffUsername,
        #[ORM\Column(length: 500, nullable: true)]
        public readonly ?string $note,
    ) {
    }
}
