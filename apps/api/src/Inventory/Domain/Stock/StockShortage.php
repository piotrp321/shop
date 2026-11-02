<?php

declare(strict_types=1);

namespace App\Inventory\Domain\Stock;

use App\Shared\Domain\Exception\Conflict;

/**
 * Less is available than a reservation line asks for. The reservation handler collects the shortages of all lines
 * into one InsufficientStock.
 */
final class StockShortage extends \DomainException implements Conflict
{
    public function __construct(
        private readonly ProductId $productId,
        private readonly int $requested,
        private readonly int $available,
    ) {
        parent::__construct('Not enough stock of the product.');
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function requested(): int
    {
        return $this->requested;
    }

    public function available(): int
    {
        return $this->available;
    }
}
