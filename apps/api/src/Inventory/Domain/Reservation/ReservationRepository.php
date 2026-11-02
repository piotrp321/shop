<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

/**
 * Reservations are kept forever: their keys are the idempotency keys of the reservation port.
 */
interface ReservationRepository
{
    /**
     * Stores a new reservation with its lines, unless a reservation with its key exists. A transaction storing the
     * same key concurrently makes this call wait until it commits (then false) or rolls back (then true).
     *
     * Must run inside a transaction (command.bus has doctrine_transaction).
     *
     * @return bool false when the key is taken and nothing was stored
     */
    public function add(Reservation $reservation): bool;

    /**
     * The stored reservation, read without a lock.
     */
    public function find(ReservationKey $key): ?Reservation;

    /**
     * Locks the reservation until the end of the current transaction and returns it as it is once locked, so
     * transitions of one reservation run one after another.
     */
    public function lock(ReservationKey $key): ?Reservation;

    /**
     * Writes the status of a reservation locked by lock() in this transaction.
     */
    public function save(Reservation $reservation): void;
}
