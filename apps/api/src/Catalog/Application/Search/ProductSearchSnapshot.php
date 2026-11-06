<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * Read-only view of a product with everything the search index needs, so the index never hydrates aggregates.
 */
final readonly class ProductSearchSnapshot
{
    /**
     * @param list<string>                                  $additionalCategoryIds sorted ascending
     * @param list<list<string>>                            $categoryPaths         the main category's path first, then the additional categories' paths
     *                                                                             in the order of $additionalCategoryIds; each path lists category ids
     *                                                                             from the root to the category inclusive
     * @param array{key: string, alt: string}|null          $mainImage             the first image, null when the product has none
     * @param list<string>                                  $categoryNames         the names of the product's categories and all their ancestors,
     *                                                                             one per category, ordered by their path
     * @param list<string>                                  $variantAxes           the names of the product's axes, in order (none for a default variant)
     * @param list<array{id: string, values: list<string>}> $variants              in position order; the values in the order of the axes
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $name,
        public string $slug,
        public string $shortDescription,
        public string $mainCategoryId,
        public array $additionalCategoryIds,
        public array $categoryPaths,
        public ?array $mainImage,
        public array $categoryNames,
        public array $variantAxes,
        public array $variants,
    ) {
    }
}
