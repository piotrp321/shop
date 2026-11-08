<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One email of an order (`kind`, NotificationKind): claimed for sending until `claimed_until`, sent at `sent_at`.
 * Only defines the schema: DbalOrderNotifications reads and writes the rows by DBAL. The foreign key to the order has
 * no ON DELETE CASCADE, like the lines: orders are kept for ever. The migration adds the CHECK constraints of the
 * kinds and of a sent email without a claim.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ordering_order_notification')]
// DBAL requires an index with exactly the foreign key column; the primary key (order_id, kind) does not fulfil it, so
// it is named here instead of being auto-generated as IDX_*.
#[ORM\Index(name: 'ordering_order_notification_order_id_idx', columns: ['order_id'])]
class OrderNotificationRecord
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $claimedUntil = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $sentAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: OrderRecord::class)]
        #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false)]
        public readonly OrderRecord $order,
        #[ORM\Id]
        #[ORM\Column(length: 20)]
        public readonly string $kind,
    ) {
    }
}
