<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Domain\Reservation\Reservation;
use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Reservation\ReservationRepository;

/**
 * Keeps reservations like the database does: reads return fresh copies, a taken key is refused.
 */
final class InMemoryReservationRepository implements ReservationRepository
{
    /** @var array<string, Reservation> */
    private array $reservations = [];
    public int $writes = 0;

    public function add(Reservation $reservation): bool
    {
        $key = $reservation->key()->toString();
        if (isset($this->reservations[$key])) {
            return false;
        }
        $this->reservations[$key] = $this->copy($reservation);
        ++$this->writes;

        return true;
    }

    public function find(ReservationKey $key): ?Reservation
    {
        return $this->stored($key->toString());
    }

    public function lock(ReservationKey $key): ?Reservation
    {
        return $this->stored($key->toString());
    }

    public function save(Reservation $reservation): void
    {
        $key = $reservation->key()->toString();
        if (!isset($this->reservations[$key])) {
            throw new \LogicException('Only a stored reservation can be saved.');
        }
        $this->reservations[$key] = $this->copy($reservation);
        ++$this->writes;
    }

    public function stored(string $key): ?Reservation
    {
        $stored = $this->reservations[$key] ?? null;

        return null === $stored ? null : $this->copy($stored);
    }

    /**
     * @return array{array<string, Reservation>, int}
     */
    public function snapshot(): array
    {
        return [$this->reservations, $this->writes];
    }

    /**
     * @param array{array<string, Reservation>, int} $snapshot
     */
    public function restore(array $snapshot): void
    {
        [$this->reservations, $this->writes] = $snapshot;
    }

    private function copy(Reservation $reservation): Reservation
    {
        return Reservation::reconstitute($reservation->key(), $reservation->lines(), $reservation->status());
    }
}
