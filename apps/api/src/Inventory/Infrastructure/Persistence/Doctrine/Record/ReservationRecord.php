<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the Reservation aggregate; its lines are the ReservationLineRecord rows of the same key.
 * DoctrineReservationRepository maps the aggregate to and from these rows. The key is the primary key: a reservation
 * is stored once and kept forever.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_reservation')]
class ReservationRecord
{
    #[ORM\Column(length: 16)]
    public string $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        public readonly string $reservationKey,
    ) {
    }
}
