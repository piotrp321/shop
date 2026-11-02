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
 * as they are saved by one write. Version 0 is a stock that no change was saved for yet.
 *
 * All operations are serialised by a row lock (see StockItemRepository::lockMany), not by the version; a correction
 * from the panel also checks the version of the view it is based on (the handler does).
 */
final class StockItem
{
    public const int MAX_PHYSICAL = 1_000_000;
    public const int MAX_RECEIPT = 100_000;

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
     * The stock of a product that has no stored stock: nothing on the shelf, nothing reserved, version 0.
     */
    public static function unstored(ProductId $id): self
    {
        return new self($id, 0, 0, 0, new \DateTimeImmutable());
    }

    /**
     * Puts a delivered quantity on the shelf.
     *
     * @throws InvalidStockQuantity       when the quantity is not 1 to 100 000
     * @throws PhysicalStockLimitExceeded when physical would exceed 1 000 000; nothing changes
     */
    public function receive(int $quantity, ReceiptKey $key, StockNote $note, StockChangeOrigin $origin): void
    {
        if ($quantity < 1 || $quantity > self::MAX_RECEIPT) {
            throw InvalidStockQuantity::receipt();
        }
        if ($this->physical + $quantity > self::MAX_PHYSICAL) {
            throw new PhysicalStockLimitExceeded();
        }

        $this->record(StockMovementType::Receipt, $quantity, 0, null, $note, $key->toString(), $origin);
    }

    /**
     * Sets physical to what was counted on the shelf. Counting what the stock already has changes nothing: no
     * movement, no new version.
     *
     * @return bool whether the stock changed
     *
     * @throws InvalidStockQuantity    when the count is not 0 to 1 000 000
     * @throws InvalidStockNote        when the reason needs a note and there is none
     * @throws CorrectionBelowReserved when less than is reserved was counted; nothing changes
     */
    public function correct(int $counted, CorrectionReason $reason, StockNote $note, StockChangeOrigin $origin): bool
    {
        if ($counted < 0 || $counted > self::MAX_PHYSICAL) {
            throw InvalidStockQuantity::count();
        }
        if ($reason->requiresNote() && $note->isEmpty()) {
            throw InvalidStockNote::requiredForOtherReason();
        }
        if ($counted === $this->physical) {
            return false;
        }
        if ($counted < $this->reserved) {
            throw new CorrectionBelowReserved($this->reserved);
        }

        $this->record(StockMovementType::Correction, $counted - $this->physical, 0, $reason->value, $note, null, $origin);

        return true;
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
        $this->record($type, $physicalDelta, $reservedDelta, null, StockNote::none(), $key->toString(), StockChangeOrigin::system());
    }

    private function record(StockMovementType $type, int $physicalDelta, int $reservedDelta, ?string $reason, StockNote $note, ?string $reference, StockChangeOrigin $origin): void
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
            $reason,
            $note->toNullableString(),
            $reference,
            $origin,
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
