<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

interface ProductRepository
{
    /**
     * @throws SlugAlreadyTaken
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
