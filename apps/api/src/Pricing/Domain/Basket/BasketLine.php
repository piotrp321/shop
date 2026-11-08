<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Basket;

use App\Pricing\Domain\Price\VariantId;

/**
 * A line of a basket to quote: a variant and its units, 1 to 99 (the limit of a cart line).
 */
final readonly class BasketLine
{
    public const int MAX_QUANTITY = 99;

    private function __construct(
        private VariantId $variantId,
        private int $quantity,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the quantity is not 1 to 99
     */
    public static function of(VariantId $variantId, int $quantity): self
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new \InvalidArgumentException('A basket line has 1 to 99 units.');
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
