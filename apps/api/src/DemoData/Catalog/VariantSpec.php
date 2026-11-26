<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

/**
 * A row of catalog_product_variant (without the product, which owns it).
 */
final readonly class VariantSpec
{
    /**
     * @param list<string> $values    one per axis of the product, none for the default variant
     * @param string       $valuesKey the JSON of the comparison key of the values, as the repository of Catalog writes it
     */
    public function __construct(
        public string $id,
        public int $position,
        public array $values,
        public string $valuesKey,
        public string $sku,
        public string $ean,
    ) {
    }
}
