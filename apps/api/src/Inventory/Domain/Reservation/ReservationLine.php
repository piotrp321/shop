<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Reservation;

use App\Inventory\Domain\Stock\VariantId;

final readonly class ReservationLine
{
    public const int MAX_QUANTITY = 1000;

    private function __construct(
        private VariantId $variantId,
        private int $quantity,
    ) {
    }

    /**
     * @throws InvalidReservation
     */
    public static function of(VariantId $variantId, int $quantity): self
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw InvalidReservation::quantityOutOfRange();
        }

        return new self($variantId, $quantity);
    }

    public function variantId(): VariantId
    {
        return $this->variantId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}
