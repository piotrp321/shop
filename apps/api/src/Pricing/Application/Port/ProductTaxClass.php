<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;

/**
 * The current tax class of a Catalog product and the variants it has now: the prices of these variants should be in
 * this class.
 */
final readonly class ProductTaxClass
{
    /**
     * @param list<VariantId> $variantIds in presentation order
     */
    public function __construct(
        public ProductId $productId,
        public TaxClass $taxClass,
        public array $variantIds,
    ) {
    }
}
