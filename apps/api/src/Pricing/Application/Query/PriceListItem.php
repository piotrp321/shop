<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * A product of the price list with its variants.
 */
final readonly class PriceListItem
{
    /**
     * @param string                 $taxClass          the current class of the product in Catalog
     * @param int                    $applicableVatRate the rate of that class today, in basis points
     * @param list<PriceListVariant> $variants          in presentation order
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $taxClass,
        public int $applicableVatRate,
        public array $variants,
    ) {
    }
}
