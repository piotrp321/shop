<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * One variant of a product in a cart and its quantity. `addedAt` orders the lines and never changes with the quantity.
 */
final readonly class CartLine
{
    public function __construct(
        private VariantId $variantId,
        private Quantity $quantity,
        private \DateTimeImmutable $addedAt,
    ) {
    }

    public function variantId(): VariantId
    {
        return $this->variantId;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    public function addedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function withQuantity(Quantity $quantity): self
    {
        return new self($this->variantId, $quantity, $this->addedAt);
    }
}
