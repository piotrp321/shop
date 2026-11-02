<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a reservation: a product (no foreign key to Catalog) and its quantity, once per reservation. The lines
 * are written together with their reservation (ReservationRecord) and never change.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'inventory_reservation_line')]
class ReservationLineRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        public readonly string $reservationKey,
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $productId,
        #[ORM\Column]
        public readonly int $quantity,
    ) {
    }
}
