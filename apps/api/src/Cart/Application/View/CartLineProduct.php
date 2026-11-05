<?php

declare(strict_types=1);

namespace App\Cart\Application\View;

use App\Cart\Application\Port\ProductImage;

/**
 * The product of a line: its id, its name, the label of the variant of the line (empty for the default variant) and
 * the main image of the product.
 */
final readonly class CartLineProduct
{
    public function __construct(
        public string $productId,
        public string $name,
        public string $variantLabel,
        public ?ProductImage $image,
    ) {
    }
}
