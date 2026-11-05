<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\VariantId;

/**
 * A line of the locked cart: the variant and its units (1 to 99).
 */
final readonly class CartLineToOrder
{
    public function __construct(
        public VariantId $variantId,
        public int $quantity,
    ) {
    }
}
