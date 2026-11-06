<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategorySlug;

final class InMemoryCategoryRepository implements CategoryRepository
{
    /** @var array<string, Category> */
    private array $categories = [];

    public function save(Category $category): void
    {
        $this->categories[$category->id()->toString()] = $category;
    }

    public function get(CategoryId $id): Category
    {
        return $this->categories[$id->toString()] ?? throw new CategoryNotFound();
    }

    public function all(): array
    {
        return array_values($this->categories);
    }

    public function count(): int
    {
        return \count($this->categories);
    }

    public function slugTakenAmongSiblings(?CategoryId $parentId, CategorySlug $slug): bool
    {
        foreach ($this->categories as $category) {
            $sameParent = null === $parentId
                ? null === $category->parentId()
                : null !== $category->parentId() && $category->parentId()->equals($parentId);
            if ($sameParent && $category->slug()->equals($slug)) {
                return true;
            }
        }

        return false;
    }
}
