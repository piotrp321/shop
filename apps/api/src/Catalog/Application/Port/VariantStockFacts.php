<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * Which variants have stock, so that the domain refuses to remove them from their product (decision A6). Catalog
 * never reads stock itself: the adapter asks the module that owns it. The answer is read without a lock.
 */
interface VariantStockFacts
{
    /**
     * @param list<string> $variantIds variant UUIDs, 1 to 100 (the variants of one product)
     *
     * @return list<string> the ids among them with physical stock > 0 or reserved stock > 0; a variant without a
     *                      stock record has none
     */
    public function inUse(array $variantIds): array;
}
