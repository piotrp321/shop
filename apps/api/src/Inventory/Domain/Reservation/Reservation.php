<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

/**
 * Units of variants promised to one caller, identified by the caller's key, which is also the idempotency key of
 * the reservation. Status: active → released | committed. A reservation never expires: only a release or a commit
 * ends it.
 *
 * The stock of the lines is changed by the application handler in the same transaction (see StockItem); the
 * reservation itself only keeps its lines and decides its transitions.
 */
final class Reservation
{
    public const int MAX_LINES = 100;

    /**
     * @param list<ReservationLine> $lines ordered by variant id
     */
    private function __construct(
        private readonly ReservationKey $key,
        private readonly array $lines,
        private ReservationStatus $status,
    ) {
    }

    /**
     * A new, active reservation.
     *
     * @param list<ReservationLine> $lines in any order
     *
     * @throws InvalidReservation for no lines, more than 100 or a repeated variant
     */
    public static function place(ReservationKey $key, array $lines): self
    {
        $count = \count($lines);
        if ($count < 1 || $count > self::MAX_LINES) {
            throw InvalidReservation::lineCountOutOfRange();
        }

        return new self($key, self::ordered($lines), ReservationStatus::Active);
    }

    /**
     * Restores a stored reservation.
     *
     * @param list<ReservationLine> $lines
     */
    public static function reconstitute(ReservationKey $key, array $lines, ReservationStatus $status): self
    {
        return new self($key, self::ordered($lines), $status);
    }

    /**
     * @return bool false when the reservation is already released
     *
     * @throws ReservationStateConflict when it is committed
     */
    public function release(): bool
    {
        return match ($this->status) {
            ReservationStatus::Active => $this->moveTo(ReservationStatus::Released),
            ReservationStatus::Released => false,
            ReservationStatus::Committed => throw ReservationStateConflict::alreadyCommitted(),
        };
    }

    /**
     * @return bool false when the reservation is already committed
     *
     * @throws ReservationStateConflict when it is released
     */
    public function commit(): bool
    {
        return match ($this->status) {
            ReservationStatus::Active => $this->moveTo(ReservationStatus::Committed),
            ReservationStatus::Committed => false,
            ReservationStatus::Released => throw ReservationStateConflict::alreadyReleased(),
        };
    }

    /**
     * Whether the lines are the same set of variants and quantities as this reservation's, in any order.
     *
     * @param list<ReservationLine> $lines
     */
    public function hasLines(array $lines): bool
    {
        return self::fingerprint($lines) === self::fingerprint($this->lines);
    }

    public function key(): ReservationKey
    {
        return $this->key;
    }

    /**
     * @return list<ReservationLine> ordered by variant id
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function status(): ReservationStatus
    {
        return $this->status;
    }

    private function moveTo(ReservationStatus $status): bool
    {
        $this->status = $status;

        return true;
    }

    /**
     * @param list<ReservationLine> $lines
     *
     * @return list<ReservationLine>
     *
     * @throws InvalidReservation for a repeated variant
     */
    private static function ordered(array $lines): array
    {
        $byVariant = [];
        foreach ($lines as $line) {
            $id = $line->variantId()->toString();
            if (isset($byVariant[$id])) {
                throw InvalidReservation::repeatedVariant();
            }
            $byVariant[$id] = $line;
        }
        ksort($byVariant, \SORT_STRING);

        return array_values($byVariant);
    }

    /**
     * @param list<ReservationLine> $lines
     *
     * @return array<string, int>
     */
    private static function fingerprint(array $lines): array
    {
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line->variantId()->toString()] = $line->quantity();
        }
        ksort($quantities, \SORT_STRING);

        return $quantities;
    }
}
