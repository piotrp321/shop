<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductEditVariant
{
    /**
     * @param list<string> $values one per axis; none for the default variant
     * @param string       $sku    empty: no SKU
     * @param string       $ean    empty: no EAN
     */
    public function __construct(
        public string $variantId,
        public array $values,
        public string $sku,
        public string $ean,
    ) {
    }
}
