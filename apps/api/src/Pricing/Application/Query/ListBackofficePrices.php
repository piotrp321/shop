<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * One page of the price list of the panel: products sorted by name (by default) or by the moment of the last change,
 * then by id, each with its variants and their prices.
 */
final readonly class ListBackofficePrices
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param string|null $term       a part of the product name, 2 to 100 characters
     * @param string|null $categoryId a category id (UUID), including its subcategories
     * @param string|null $after      the `next` of the previous page
     * @param int         $perPage    1 to 100
     * @param string|null $sort       `name`, `-name`, `-updatedAt` or `updatedAt`; null for `name`
     * @param string|null $productId  a product id (UUID): only that product (a draft too) with all its variants; excludes
     *                                the term, the category, the sort and the cursor
     */
    public function __construct(
        public ?string $term,
        public ?string $categoryId,
        public ?string $after,
        public int $perPage,
        public ?string $sort = null,
        public ?string $productId = null,
    ) {
    }
}
