<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

interface ProductRepository
{
    /**
     * Stale-write detection compares against the version read in the same unit of work (get() then save() in one
     * handler or transaction); a state read in an earlier request carries no version and is not detected.
     *
     * @throws SlugAlreadyTaken
     * @throws ProductModifiedConcurrently
     */
    public function save(Product $product): void;

    /**
     * @throws ProductNotFound
     */
    public function get(ProductId $id): Product;

    /**
     * @return iterable<Product>
     */
    public function all(): iterable;

    public function count(): int;

    public function countWithoutImages(): int;
}
