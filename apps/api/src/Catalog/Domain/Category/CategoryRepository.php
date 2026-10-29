<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

interface CategoryRepository
{
    /**
     * Inserts the category or overwrites the one with the same id.
     *
     * @throws CategoryAlreadyExists when a concurrent insert of the same id wins the race
     */
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
