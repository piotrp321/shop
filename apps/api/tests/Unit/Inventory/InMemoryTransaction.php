<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

/**
 * Plays the doctrine_transaction middleware of command.bus for the in-memory repositories: whatever a handler wrote
 * is undone when it throws.
 */
final readonly class InMemoryTransaction
{
    public function __construct(
        private InMemoryStockItemRepository $stock,
        private InMemoryReservationRepository $reservations,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function run(\Closure $work): mixed
    {
        $stock = $this->stock->snapshot();
        $reservations = $this->reservations->snapshot();
        try {
            return $work();
        } catch (\Throwable $failure) {
            $this->stock->restore($stock);
            $this->reservations->restore($reservations);

            throw $failure;
        }
    }
}
