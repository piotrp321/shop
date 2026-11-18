<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

use App\DemoData\Pricing\PriceTimeline;

/**
 * What the orders read of the catalog and the prices the earlier steps wrote: the products of the tail by their global
 * index, with their variants, and the price timeline of a variant (its history and its promotions).
 */
interface OrderCatalog
{
    /**
     * @param list<int> $indexes global indexes of products of the tail
     *
     * @return array<int, CatalogProduct> by index; an index without a product is left out
     */
    public function products(array $indexes): array;

    /**
     * @param list<string> $variantIds
     *
     * @return array<string, PriceTimeline> by the id of the variant; a variant without a price is left out
     */
    public function timelines(array $variantIds): array;
}
