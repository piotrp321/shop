<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class VariantInfo
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $taxClass,
    ) {
    }
}
