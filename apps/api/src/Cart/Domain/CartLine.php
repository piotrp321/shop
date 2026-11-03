<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * One product of a cart and its quantity. `addedAt` orders the lines and never changes with the quantity.
 */
final readonly class CartLine
{
    public function __construct(
        private ProductId $productId,
        private Quantity $quantity,
        private \DateTimeImmutable $addedAt,
    ) {
    }

    public function productId(): ProductId
    {
        return $this->productId;
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
        return new self($this->productId, $quantity, $this->addedAt);
    }
}
