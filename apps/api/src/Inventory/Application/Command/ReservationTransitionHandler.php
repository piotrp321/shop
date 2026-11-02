<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Reservation\InvalidReservation as InvalidReservationInDomain;
use App\Inventory\Domain\Reservation\Reservation;
use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Reservation\ReservationLine;
use App\Inventory\Domain\Reservation\ReservationRepository;
use App\Inventory\Domain\Reservation\ReservationStateConflict as ReservationStateConflictInDomain;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\StockItem;
use App\Inventory\Domain\Stock\StockItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ends a reservation. The reservation row is locked first, so a release and a commit of the same reservation run
 * one after another and the later one sees the status the earlier one left; then the stocks of its lines are locked
 * in ascending product id, as for a reservation. Repeating the transition the reservation already made changes
 * nothing and answers with the reservation.
 */
final readonly class ReservationTransitionHandler
{
    public function __construct(
        private ReservationRepository $reservations,
        private StockItemRepository $stock,
    ) {
    }

    /**
     * @throws InvalidReservation       for a malformed key
     * @throws ReservationNotFound
     * @throws ReservationStateConflict when the reservation is committed
     */
    #[AsMessageHandler(bus: 'command.bus')]
    public function release(ReleaseReservation $command): ReservationView
    {
        $reservation = $this->locked($command->reservationKey);
        if (self::transition($reservation->release(...))) {
            $this->applyToStock($reservation, static fn (StockItem $item, int $quantity) => $item->release($quantity, $reservation->key()));
        }

        return ReservationView::of($reservation);
    }

    /**
     * @throws InvalidReservation       for a malformed key
     * @throws ReservationNotFound
     * @throws ReservationStateConflict when the reservation is released
     */
    #[AsMessageHandler(bus: 'command.bus')]
    public function commit(CommitReservation $command): ReservationView
    {
        $reservation = $this->locked($command->reservationKey);
        if (self::transition($reservation->commit(...))) {
            $this->applyToStock($reservation, static fn (StockItem $item, int $quantity) => $item->commit($quantity, $reservation->key()));
        }

        return ReservationView::of($reservation);
    }

    /**
     * @throws InvalidReservation
     * @throws ReservationNotFound
     */
    private function locked(string $key): Reservation
    {
        try {
            $reservationKey = ReservationKey::fromString($key);
        } catch (InvalidReservationInDomain $invalid) {
            throw InvalidReservation::fromDomain($invalid);
        }

        return $this->reservations->lock($reservationKey) ?? throw new ReservationNotFound();
    }

    /**
     * @param \Closure(): bool $transition
     *
     * @throws ReservationStateConflict
     */
    private static function transition(\Closure $transition): bool
    {
        try {
            return $transition();
        } catch (ReservationStateConflictInDomain $conflict) {
            throw ReservationStateConflict::fromDomain($conflict);
        }
    }

    /**
     * @param \Closure(StockItem, int): void $change
     */
    private function applyToStock(Reservation $reservation, \Closure $change): void
    {
        $items = [];
        foreach ($this->stock->lockMany(array_map(static fn (ReservationLine $line): ProductId => $line->productId(), $reservation->lines())) as $item) {
            $items[$item->id()->toString()] = $item;
        }
        foreach ($reservation->lines() as $line) {
            // An active reservation holds reserved units of every line, so its stocks exist.
            $item = $items[$line->productId()->toString()] ?? throw new \LogicException('A reserved product has no stock.');
            $change($item, $line->quantity());
        }

        foreach ($items as $item) {
            $this->stock->save($item);
        }
        $this->reservations->save($reservation);
    }
}
