<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * One page of products for the price list of the panel, sorted by name, then by id, with keyset paging.
 */
final readonly class SearchBackofficeProducts
{
    public const int MIN_TERM_LENGTH = 2;
    public const int MAX_TERM_LENGTH = 100;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param string|null $term       a part of the product name, 2 to 100 characters; null for no name filter
     * @param string|null $categoryId a category id (UUID); the category with its subcategories, as the main or an
     *                                additional category of the product; null for no category filter
     * @param string|null $after      the `next` cursor of the previous page, null for the first page
     * @param int         $perPage    1 to 100
     * @param string|null $status     `draft` or `published`; null for products of both statuses
     */
    public function __construct(
        public ?string $term,
        public ?string $categoryId,
        public ?string $after,
        public int $perPage,
        public ?string $status = null,
    ) {
    }
}
