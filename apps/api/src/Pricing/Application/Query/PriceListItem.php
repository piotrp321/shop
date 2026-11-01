<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

final readonly class PriceListItem
{
    /**
     * @param string           $taxClass          the current class of the product in Catalog
     * @param int              $applicableVatRate the rate of that class today, in basis points
     * @param ListedPrice|null $price             null when the product has no price yet
     */
    public function __construct(
        public string $productId,
        public string $name,
        public string $taxClass,
        public int $applicableVatRate,
        public ?ListedPrice $price,
    ) {
    }
}
