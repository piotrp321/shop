<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

/**
 * A product of Catalog with its variants.
 */
final readonly class DescribedProduct
{
    /**
     * @param list<DirectoryVariant> $variants in presentation order
     */
    public function __construct(
        public DirectoryProduct $product,
        public array $variants,
    ) {
    }
}
