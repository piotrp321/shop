<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\ProductId;
use App\Cart\Domain\VariantId;

/**
 * A variant Catalog knows: its product, the name of the product, its label (empty for the default variant) and the
 * main image of the product (null without images).
 */
final readonly class VariantSummary
{
    public function __construct(
        public VariantId $variantId,
        public ProductId $productId,
        public string $name,
        public string $label,
        public ?ProductImage $image,
    ) {
    }
}
