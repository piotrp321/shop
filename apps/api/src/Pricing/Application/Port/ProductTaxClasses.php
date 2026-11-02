<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Tax\TaxClass;

/**
 * The current tax class of a product, as Catalog assigns it.
 */
interface ProductTaxClasses
{
    /**
     * @return TaxClass|null null when the product does not exist
     */
    public function taxClassOf(ProductId $productId): ?TaxClass;
}
