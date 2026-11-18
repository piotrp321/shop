<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A variant of a product as a line of an order copies it: the id and the label of its values (empty for a default
 * variant, VariantValues::label of Catalog).
 */
final readonly class CatalogVariant
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
