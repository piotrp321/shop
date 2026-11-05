<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\ProductId;

/**
 * What an order line copies of a variant from Catalog: its product, the product name and the variant label (empty
 * for the default variant).
 */
final readonly class CatalogVariant
{
    public function __construct(
        public ProductId $productId,
        public string $productName,
        public string $variantLabel,
    ) {
    }
}
