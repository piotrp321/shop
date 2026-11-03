<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

/**
 * The products of Catalog to show the stock of, found by name and category, one page at a time.
 */
interface ProductDirectory
{
    /**
     * Products sorted by name, then by id. The cursor is opaque: only a `next` handed out by this port is valid.
     *
     * @param string|null $term       a literal part of the name, 2 to 100 characters; null for all names
     * @param string|null $categoryId a category (UUID); the category with its subcategories, as the main or an
     *                                additional category of the product; null for all categories
     * @param string|null $after      the `next` of the previous page, null for the first page
     * @param int         $perPage    1 to 100
     *
     * @throws CategoryNotFound
     * @throws InvalidStockListCursor
     */
    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage): DirectoryPage;
}
