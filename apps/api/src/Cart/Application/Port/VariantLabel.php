<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

use App\Cart\Domain\VariantId;

/**
 * A variant of a product and its label (empty for the default variant).
 */
final readonly class VariantLabel
{
    public function __construct(
        public VariantId $variantId,
        public string $label,
    ) {
    }
}
