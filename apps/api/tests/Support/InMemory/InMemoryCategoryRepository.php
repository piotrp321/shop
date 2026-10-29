<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;

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
}
