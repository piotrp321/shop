<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

interface CategoryRepository
{
    public function save(Category $category): void;

    /**
     * @throws CategoryNotFound
     */
    public function get(CategoryId $id): Category;

    /**
     * @return list<Category>
     */
    public function all(): array;

    public function count(): int;
}
