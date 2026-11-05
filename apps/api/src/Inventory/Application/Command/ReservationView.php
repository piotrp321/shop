<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Reservation\Reservation;
use App\Inventory\Domain\Reservation\ReservationLine;

/**
 * A reservation as stored: its key, its status (active, released or committed) and its lines by variant id.
 */
final readonly class ReservationView
{
    /**
     * @param list<ReservationLineView> $lines ordered by variant id
     */
    public function __construct(
        public string $key,
        public string $status,
        public array $lines,
    ) {
    }

    public static function of(Reservation $reservation): self
    {
        return new self(
            $reservation->key()->toString(),
            $reservation->status()->value,
            array_map(
                static fn (ReservationLine $line): ReservationLineView => new ReservationLineView($line->variantId()->toString(), $line->quantity()),
                $reservation->lines(),
            ),
        );
    }
}
