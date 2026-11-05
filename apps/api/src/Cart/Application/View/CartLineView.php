<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

/**
 * A line as the store shows it. `product` is null for a variant Catalog does not know; `lineTotal` is set only for
 * a purchasable line. The available units are never part of it.
 */
final readonly class CartLineView
{
    public function __construct(
        public string $variantId,
        public ?CartLineProduct $product,
        public int $quantity,
        public ?GrossAmount $unitPrice,
        public ?GrossAmount $lineTotal,
        public string $state,
    ) {
    }
}
