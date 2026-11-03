<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

use App\Inventory\Domain\Stock\ProductId;

final readonly class ReservationLine
{
    public const int MAX_QUANTITY = 1000;

    private function __construct(
        private ProductId $productId,
        private int $quantity,
    ) {
    }

    /**
     * @throws InvalidReservation
     */
    public static function of(ProductId $productId, int $quantity): self
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw InvalidReservation::quantityOutOfRange();
        }

        return new self($productId, $quantity);
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}
