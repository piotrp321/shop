<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

/**
 * The writes of the tree run under the lock of the tree (Application port CategoryTreeLock), so a read in a write
 * sees what the others committed.
 */
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
     * save() of every category, in one write.
     *
     * @param list<Category> $categories
     *
     * @throws CategorySlugTaken
     */
    public function saveAll(array $categories): void;

    /**
     * @throws CategoryNotFound
     */
    public function get(CategoryId $id): Category;

    /**
     * Every category as stored, also when an earlier read of the same process holds an older state.
     *
     * @return list<Category>
     */
    public function all(): array;

    public function count(): int;

    /**
     * The path of a moved category and of its descendants: every stored path that starts with $from starts with $to
     * instead.
     */
    public function movePaths(CategoryPath $from, CategoryPath $to): void;

    /**
     * Locks the row of the category until the end of the transaction, so no product is assigned to it meanwhile.
     */
    public function lock(CategoryId $id): void;

    /**
     * Deletes the category; its redirects go with it.
     */
    public function remove(Category $category): void;
}
