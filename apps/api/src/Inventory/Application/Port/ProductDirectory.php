<?php

declare(strict_types=1);

namespace App\Inventory\Application\Port;

use App\Inventory\Domain\Stock\ProductId;

/**
 * The products of Catalog to show the stock of, found by name and category, one page at a time, and the variants of
 * products.
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

    /**
     * The variants of the products, read with one query.
     *
     * @param list<ProductId> $productIds 1 to 100
     *
     * @return array<string, list<DirectoryVariant>> product id => its variants in presentation order; a product that
     *                                               does not exist in Catalog is absent
     */
    public function variantsOf(array $productIds): array;
}
