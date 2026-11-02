<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\Reservation\InvalidReservation;
use App\Inventory\Domain\Reservation\Reservation;
use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Reservation\ReservationLine;
use App\Inventory\Domain\Reservation\ReservationRepository;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Domain\Stock\StockItemRepository;
use App\Inventory\Domain\Stock\StockShortage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReserveStockHandler
{
    public function __construct(
        private ReservationRepository $reservations,
        private StockItemRepository $stock,
    ) {
    }

    /**
     * Stores the reservation first: a concurrent reservation with the same key waits on it, and a taken key answers
     * with the stored reservation (same lines, whatever its status now) or ReservationKeyReused, without touching
     * stock. A new key locks the stocks of the lines and reserves every line; a shortage of any line throws, which
     * rolls back the transaction of command.bus, the stored key included. A product without a stored stock has
     * nothing available.
     *
     * @throws InvalidReservation
     * @throws ReservationKeyReused
     * @throws InsufficientStock
     */
    public function __invoke(ReserveStock $command): ReservationView
    {
        $reservation = Reservation::place(
            ReservationKey::fromString($command->reservationKey),
            array_map(self::line(...), $command->lines),
        );

        if (!$this->reservations->add($reservation)) {
            $stored = $this->reservations->find($reservation->key())
                ?? throw new \LogicException('A taken reservation key has no reservation.');
            if (!$stored->hasLines($reservation->lines())) {
                throw new ReservationKeyReused();
            }

            return ReservationView::of($stored);
        }

        $items = [];
        foreach ($this->stock->lockMany(array_map(static fn (ReservationLine $line): ProductId => $line->productId(), $reservation->lines())) as $item) {
            $items[$item->id()->toString()] = $item;
        }

        $shortages = [];
        foreach ($reservation->lines() as $line) {
            $item = $items[$line->productId()->toString()] ?? null;
            if (null === $item) {
                $shortages[] = new Shortage($line->productId()->toString(), $line->quantity(), 0);
                continue;
            }
            try {
                $item->reserve($line->quantity(), $reservation->key());
            } catch (StockShortage $shortage) {
                $shortages[] = new Shortage($shortage->productId()->toString(), $shortage->requested(), $shortage->available());
            }
        }
        if ([] !== $shortages) {
            throw new InsufficientStock($shortages);
        }

        foreach ($items as $item) {
            $this->stock->save($item);
        }

        return ReservationView::of($reservation);
    }

    /**
     * @throws InvalidReservation
     */
    private static function line(ReserveStockLine $line): ReservationLine
    {
        try {
            $productId = ProductId::fromString($line->productId);
        } catch (\InvalidArgumentException) {
            throw InvalidReservation::notAProductId();
        }

        return ReservationLine::of($productId, $line->quantity);
    }
}
