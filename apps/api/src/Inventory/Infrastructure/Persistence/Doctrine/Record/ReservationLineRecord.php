<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a reservation: a variant (no foreign key to Catalog) and its quantity, once per reservation. The lines
 * are written together with their reservation (ReservationRecord, a foreign key without cascade) and never change.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'inventory_reservation_line')]
class ReservationLineRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: ReservationRecord::class)]
        #[ORM\JoinColumn(name: 'reservation_key', referencedColumnName: 'reservation_key')]
        public readonly ReservationRecord $reservation,
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $variantId,
        #[ORM\Column]
        public readonly int $quantity,
    ) {
    }
}
