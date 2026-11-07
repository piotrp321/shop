<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

interface CategoryRepository
{
    /**
     * Inserts the category or overwrites the one with the same id.
     *
     * @throws CategoryAlreadyExists when a concurrent insert of the same id wins the race
     * @throws CategorySlugTaken     when a concurrent insert of a sibling with the same slug wins the race
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

    /**
     * Whether a child of the parent (a root when the parent is null) already has the slug.
     */
    public function slugTakenAmongSiblings(?CategoryId $parentId, CategorySlug $slug): bool;
}
