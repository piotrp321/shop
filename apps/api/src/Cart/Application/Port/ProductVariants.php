<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\ProductId;

/**
 * The variant axes and the variants of a product Catalog knows, the variants in presentation order.
 */
final readonly class ProductVariants
{
    /**
     * @param list<string>       $variantAxes names of the axes, none for a product with the default variant
     * @param list<VariantLabel> $variants
     */
    public function __construct(
        public ProductId $productId,
        public array $variantAxes,
        public array $variants,
    ) {
    }
}
