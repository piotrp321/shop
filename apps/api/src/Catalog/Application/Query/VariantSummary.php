<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * A variant as a list of other modules shows it: the name of its product, its label (empty for the default variant)
 * and the main image of the product, null when it has none.
 */
final readonly class VariantSummary
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $name,
        public string $label,
        public ?ImageView $image,
    ) {
    }
}
