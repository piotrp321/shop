<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;

/**
 * The current tax class of a variant: the class Catalog assigns to the product of the variant.
 */
interface VariantTaxClasses
{
    /**
     * @return TaxClass|null null when the variant does not exist
     */
    public function taxClassOf(VariantId $variantId): ?TaxClass;
}
