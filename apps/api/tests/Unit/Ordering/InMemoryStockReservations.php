<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\OrderId;

/**
 * Available units by variant; a reservation takes all its lines or none, like Inventory.
 */
final class InMemoryStockReservations implements StockReservations
{
    /** @var array<string, int> */
    private array $available = [];
    /** @var array<string, array<string, int>> lines (quantity by variant) by reservation key */
    public array $reservations = [];

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
}
