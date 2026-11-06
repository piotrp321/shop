<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Search\OptionFacet;
use App\Catalog\Application\Search\PriceRange;

final readonly class ProductFacets
{
    /**
     * @param list<CategoryFacet> $categories     the children of the filter category (the roots without one) that have products, in tree order
     * @param list<OptionFacet>   $options        the axes of the products found, each value counted as the total after adding it to the filter
     * @param PriceRange|null     $price          gross prices of the buyable variants matching the options, null without any
     * @param int                 $availableCount the total after adding "only available" to the filter
     */
    public function __construct(
        public array $categories,
        public array $options,
        public ?PriceRange $price,
        public int $availableCount,
    ) {
    }
}
