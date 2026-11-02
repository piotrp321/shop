<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine;

use App\Inventory\Domain\Reservation\Reservation;
use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Reservation\ReservationLine;
use App\Inventory\Domain\Reservation\ReservationRepository;
use App\Inventory\Domain\Reservation\ReservationStatus;
use App\Inventory\Domain\Stock\ProductId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Maps the Reservation aggregate to and from its rows (see Record\ReservationRecord and Record\ReservationLineRecord)
 * with plain statements on the connection of the entity manager, so they join the transaction of command.bus. No
 * identity map: a reservation is always read as stored, and the header of a reservation rolled back (a shortage)
 * leaves no managed record that a later attempt with the same key would collide with.
 */
final readonly class DoctrineReservationRepository implements ReservationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * INSERT … ON CONFLICT DO NOTHING of the header: PostgreSQL makes a transaction inserting a key that another,
     * uncommitted transaction inserted wait for it, then skips the row if it committed. The lines follow in one
     * statement.
     */
    public function add(Reservation $reservation): bool
    {
        $key = $reservation->key()->toString();
        $now = new \DateTimeImmutable();
        $inserted = $this->connection->executeStatement(
            'INSERT INTO inventory_reservation (reservation_key, status, created_at, updated_at) VALUES (:key, :status, :now, :now) ON CONFLICT (reservation_key) DO NOTHING',
            ['key' => $key, 'status' => $reservation->status()->value, 'now' => $now],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
        if (0 === $inserted) {
            return false;
        }

        $values = [];
        $parameters = [];
        foreach ($reservation->lines() as $line) {
            $values[] = '(?, ?, ?)';
            array_push($parameters, $key, $line->productId()->toString(), $line->quantity());
        }
        $this->connection->executeStatement(
            'INSERT INTO inventory_reservation_line (reservation_key, product_id, quantity) VALUES '.implode(', ', $values),
            $parameters,
        );

        return true;
    }

    public function find(ReservationKey $key): ?Reservation
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE reservation_key = :key',
            ['key' => $key->toString()],
        );

        return false === $status ? null : $this->withLines($key, $status);
    }

    /**
     * SELECT … FOR UPDATE of the header. Under READ COMMITTED, a reservation whose status was changed by the
     * transaction that held the lock is returned with the status that transaction committed. The lines never change,
     * so they are read without a lock.
     */
    public function lock(ReservationKey $key): ?Reservation
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM inventory_reservation WHERE reservation_key = :key FOR UPDATE',
            ['key' => $key->toString()],
        );

        return false === $status ? null : $this->withLines($key, $status);
    }

    public function save(Reservation $reservation): void
    {
        $updated = $this->connection->executeStatement(
            'UPDATE inventory_reservation SET status = :status, updated_at = :now WHERE reservation_key = :key',
            ['status' => $reservation->status()->value, 'now' => new \DateTimeImmutable(), 'key' => $reservation->key()->toString()],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
        if (1 !== $updated) {
            throw new \LogicException('Only a stored reservation can be saved.');
        }
    }

    private function withLines(ReservationKey $key, mixed $status): Reservation
    {
        \assert(\is_string($status));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_id, quantity FROM inventory_reservation_line WHERE reservation_key = :key',
            ['key' => $key->toString()],
        );

        return Reservation::reconstitute(
            $key,
            array_map(static function (array $row): ReservationLine {
                \assert(\is_string($row['product_id']) && \is_int($row['quantity']));

                return ReservationLine::of(ProductId::fromString($row['product_id']), $row['quantity']);
            }, $rows),
            ReservationStatus::from($status),
        );
    }
}
