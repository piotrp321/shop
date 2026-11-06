<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * What a product search asks for. The variant conditions (options, price range, availability) all hold for one and the
 * same variant of a product.
 */
final readonly class ProductSearchCriteria
{
    /**
     * @param int                             $page             from 1
     * @param int                             $perPage          from 1
     * @param string|null                     $categoryId       the category and all its descendants
     * @param string|null                     $phrase           the trimmed phrase, every word of it must match
     * @param array<int|string, list<string>> $options          wanted values per axis name: any of the values of an axis, and every axis
     * @param int|null                        $priceMin         gross, in grosze
     * @param int|null                        $priceMax         gross, in grosze
     * @param bool                            $availableOnly    only products with a variant that can be bought now
     * @param list<string>                    $facetCategoryIds the categories to count in the category facet, in the order wanted
     *
     * @throws \InvalidArgumentException when a number is out of range, the price range is inverted or the relevance sort has no phrase
     */
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $categoryId = null,
        public ?string $phrase = null,
        public array $options = [],
        public ?int $priceMin = null,
        public ?int $priceMax = null,
        public bool $availableOnly = false,
        private ?ProductSort $sort = null,
        public array $facetCategoryIds = [],
    ) {
        if ($page < 1 || $perPage < 1) {
            throw new \InvalidArgumentException('The page and the page size must be at least 1.');
        }
        if ((null !== $priceMin && $priceMin < 0) || (null !== $priceMax && $priceMax < 0)) {
            throw new \InvalidArgumentException('A price must not be negative.');
        }
        if (null !== $priceMin && null !== $priceMax && $priceMin > $priceMax) {
            throw new \InvalidArgumentException('The minimum price must not be higher than the maximum price.');
        }
        if (ProductSort::Relevance === $sort && null === $phrase) {
            throw new \InvalidArgumentException('Sorting by relevance needs a phrase.');
        }
    }

    /**
     * The sort asked for; relevance with a phrase, otherwise by name.
     */
    public function sort(): ProductSort
    {
        return $this->sort ?? (null === $this->phrase ? ProductSort::Name : ProductSort::Relevance);
    }
}
