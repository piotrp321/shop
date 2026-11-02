<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Query\ProductForPricing;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\TaxClassCode;

/**
 * What Pricing needs to know about products: the tax class of one, and all of them in batches.
 */
interface ProductsForPricingSource
{
    public function taxClassOf(ProductId $id): ?TaxClassCode;

    /**
     * @param int $limit at least 1
     *
     * @return list<ProductForPricing> ordered by id, starting after $after
     */
    public function page(?ProductId $after, int $limit): array;
}
