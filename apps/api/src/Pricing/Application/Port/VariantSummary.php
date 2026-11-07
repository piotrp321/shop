<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\VariantId;

/**
 * A variant as Catalog shows it in other modules: its product, the name of the product and its label (empty for the
 * default variant).
 */
final readonly class VariantSummary
{
    public function __construct(
        public VariantId $variantId,
        public string $productId,
        public string $productName,
        public string $label,
    ) {
    }
}
