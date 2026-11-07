<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\OrderId;

/**
 * Available units by variant; a reservation takes all its lines or none, like Inventory. A release gives the units
 * back once (a released reservation stays released) and fails like Inventory for a reservation it does not know.
 */
final class InMemoryStockReservations implements StockReservations
{
    /** @var array<string, int> */
    private array $available = [];
    /** @var array<string, array<string, int>> lines (quantity by variant) by reservation key */
    public array $reservations = [];
    /** @var list<string> the keys of the reservations released, once per release call */
    public array $released = [];
    /** Thrown by the next release instead of releasing (e.g. a reservation Inventory does not know). */
    public ?\Throwable $releaseFailure = null;
    /** Runs while a reservation is being released, e.g. to let a concurrent handler change the order first. */
    public ?\Closure $whileReleasing = null;

    public function __construct(private readonly Journal $journal)
    {
    }

    public function stock(string $variantId, int $available): self
    {
        $this->available[$variantId] = $available;

        return $this;
    }

    public function reserve(OrderId $orderId, array $lines): void
    {
        $this->journal->record('reserve');
        foreach ($lines as $line) {
            if (($this->available[$line->variantId->toString()] ?? 0) < $line->quantity) {
                throw new InsufficientStockForOrder();
            }
        }
        $reserved = [];
        foreach ($lines as $line) {
            $this->available[$line->variantId->toString()] -= $line->quantity;
            $reserved[$line->variantId->toString()] = $line->quantity;
        }
        $this->reservations[$orderId->toString()] = $reserved;
    }

    public function release(OrderId $orderId): void
    {
        $this->journal->record('release');
        if (null !== $this->releaseFailure) {
            throw $this->releaseFailure;
        }
        if (null !== $this->whileReleasing) {
            ($this->whileReleasing)();
        }
        $this->released[] = $orderId->toString();
    }
}
