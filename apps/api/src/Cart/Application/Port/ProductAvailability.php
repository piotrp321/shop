<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\ProductId;

/**
 * The units of a product available now (Inventory). Never shown to a client.
 */
final readonly class ProductAvailability
{
    public function __construct(
        public ProductId $productId,
        public int $available,
    ) {
    }
}
