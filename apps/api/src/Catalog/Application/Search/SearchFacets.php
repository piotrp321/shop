<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * The counts that go with a search result, each one computed with the conditions of the search except its own.
 */
final readonly class SearchFacets
{
    /**
     * @param array<string, int> $categoryCounts the products per requested category, only categories with products
     * @param list<OptionFacet>  $options        the axes of the products found, by number of products descending
     * @param PriceRange|null    $price          the prices of the buyable variants matching the options, null without any
     * @param int                $availableCount the products the search would return with availability added to the filter
     */
    public function __construct(
        public array $categoryCounts,
        public array $options,
        public ?PriceRange $price,
        public int $availableCount,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], null, 0);
    }
}
