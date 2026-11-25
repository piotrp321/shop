<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ProductDetailsVariant
{
    /**
     * @param list<string> $values one value per axis, in the order of the axes
     * @param string       $sku    the code of the seller, empty when there is none
     * @param string       $ean    the EAN of the seller, empty when there is none
     * @param string       $gtin   the EAN when it is a global trade item number, empty for a number of restricted circulation and when there is no EAN
     */
    public function __construct(
        public string $variantId,
        public string $label,
        public array $values,
        public string $sku,
        public string $ean,
        public string $gtin,
    ) {
    }
}
