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
     * Products sorted by name, or by the moment of the last change, then by id. The cursor is opaque: only a `next`
     * handed out by this port for the same sort is valid.
     *
     * @param string|null $term       a literal part of the name, 2 to 100 characters; null for all names
     * @param string|null $categoryId a category (UUID); the category with its subcategories, as the main or an
     *                                additional category of the product; null for all categories
     * @param string|null $after      the `next` of the previous page, null for the first page
     * @param int         $perPage    1 to 100
     * @param string|null $sort       `name`, `-name`, `-updatedAt` or `updatedAt`; null for `name`
     *
     * @throws CategoryNotFound
     * @throws InvalidStockListCursor
     */
    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage, ?string $sort = null): DirectoryPage;

    /**
     * The variants of the products, read with one query.
     *
     * @param list<ProductId> $productIds 1 to 100
     *
     * @return array<string, list<DirectoryVariant>> product id => its variants in presentation order; a product that
     *                                               does not exist in Catalog is absent
     */
    public function variantsOf(array $productIds): array;

    /**
     * The names and the variants of the products, read with one query; the products of a stock level page, whose ids
     * Inventory knows already.
     *
     * @param list<ProductId> $productIds 1 to 100
     *
     * @return array<string, DescribedProduct> by product id; a product that does not exist in Catalog is absent
     */
    public function describe(array $productIds): array;
}
