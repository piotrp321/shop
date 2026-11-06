<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Domain\VariantId;

/**
 * The variants of an order as Catalog has them now, asked once for all the lines.
 */
interface OrderCatalog
{
    /**
     * @param non-empty-list<VariantId> $variantIds at most 50, each once
     *
     * @return array<string, CatalogVariant> by variant id; a variant Catalog does not know is left out
     */
    public function variants(array $variantIds): array;
}
