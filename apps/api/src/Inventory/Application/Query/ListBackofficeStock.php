<?php

declare(strict_types=1);

namespace App\Inventory\Application\Query;

/**
 * One page of the stock list of the panel: products with their stock, sorted by name, then by id.
 */
final readonly class ListBackofficeStock
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param string|null $term       a part of the product name, 2 to 100 characters
     * @param string|null $categoryId a category id (UUID), including its subcategories
     * @param string|null $after      the `next` of the previous page
     * @param int         $perPage    1 to 100
     */
    public function __construct(
        public ?string $term,
        public ?string $categoryId,
        public ?string $after,
        public int $perPage,
    ) {
    }
}
