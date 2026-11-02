<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Inventory\Domain\Reservation\ReservationKey;

/**
 * The stock of one product in the one warehouse, in whole units: physical (on the shelf) and reserved (promised to
 * active reservations). Available = physical − reserved is computed, never stored.
 *
 * Invariant: 0 ≤ reserved ≤ physical ≤ 1 000 000. Every change records one movement with the state after it. The
 * version is the one the state will have once saved: the changes made before the next save share one version step,
 * as they are saved by one write.
 *
 * The reservation operations are serialised by a row lock (see StockItemRepository::lockMany), not by the version.
 */
final class StockItem
{
    public const int MAX_PHYSICAL = 1_000_000;

    /** @var list<StockMovement> */
    private array $unsavedMovements = [];

    private function __construct(
        private readonly ProductId $id,
        private int $physical,
        private int $reserved,
        private int $version,
        private \DateTimeImmutable $updatedAt,
    ) {
        self::guard($physical, $reserved);
    }

    /**
     * Restores a stored stock without recording movements.
     *
     * @throws StockInvariantViolation
     */
    public static function reconstitute(ProductId $id, int $physical, int $reserved, int $version, \DateTimeImmutable $updatedAt): self
    {
        return new self($id, $physical, $reserved, $version, $updatedAt);
    }

    /**
     * @throws StockShortage when less than the quantity is available; nothing changes
     */
    public function reserve(int $quantity, ReservationKey $key): void
    {
        self::requirePositive($quantity);
        if ($this->available() < $quantity) {
            throw new StockShortage($this->id, $quantity, $this->available());
        }

        $this->apply(StockMovementType::Reservation, 0, $quantity, $key);
    }

    /**
     * Gives back a reserved quantity.
     *
     * @throws StockInvariantViolation when less than the quantity is reserved
     */
    public function release(int $quantity, ReservationKey $key): void
    {
        self::requirePositive($quantity);

        $this->apply(StockMovementType::Release, 0, -$quantity, $key);
    }

    /**
     * Takes a reserved quantity off the shelf: it leaves both physical and reserved.
     *
     * @throws StockInvariantViolation when less than the quantity is reserved
     */
    public function commit(int $quantity, ReservationKey $key): void
    {
        self::requirePositive($quantity);

        $this->apply(StockMovementType::Commit, -$quantity, -$quantity, $key);
    }

    public function available(): int
    {
        return $this->physical - $this->reserved;
    }

    /**
     * @return list<StockMovement> the movements recorded since the last call, oldest first
     */
    public function releaseMovements(): array
    {
        $movements = $this->unsavedMovements;
        $this->unsavedMovements = [];

        return $movements;
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function physical(): int
    {
        return $this->physical;
    }

    public function reserved(): int
    {
        return $this->reserved;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Operations of the reservation port, so the movement has the system as its origin and the reservation key as
     * its reference.
     */
    private function apply(StockMovementType $type, int $physicalDelta, int $reservedDelta, ReservationKey $key): void
    {
        $physical = $this->physical + $physicalDelta;
        $reserved = $this->reserved + $reservedDelta;
        self::guard($physical, $reserved);

        if ([] === $this->unsavedMovements) {
            ++$this->version;
        }
        $this->physical = $physical;
        $this->reserved = $reserved;
        $this->updatedAt = new \DateTimeImmutable();
        $this->unsavedMovements[] = new StockMovement(
            $this->id,
            $type,
            $physicalDelta,
            $reservedDelta,
            $physical,
            $reserved,
            null,
            null,
            $key->toString(),
            StockChangeOrigin::system(),
            $this->updatedAt,
        );
    }

    private static function guard(int $physical, int $reserved): void
    {
        if ($reserved < 0 || $reserved > $physical || $physical > self::MAX_PHYSICAL) {
            throw new StockInvariantViolation(\sprintf('A stock must keep 0 ≤ reserved ≤ physical ≤ %d.', self::MAX_PHYSICAL));
        }
    }

    private static function requirePositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('The quantity must be at least 1.');
        }
    }
}
