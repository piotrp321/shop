<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductVariantList
{
    /**
     * @param list<string>        $variantAxes names of the axes, none for a product with the default variant
     * @param list<VariantOption> $variants    in presentation order
     */
    public function __construct(
        public string $productId,
        public array $variantAxes,
        public array $variants,
    ) {
    }
}
