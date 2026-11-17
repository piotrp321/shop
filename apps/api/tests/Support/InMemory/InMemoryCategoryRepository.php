<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryPath;
use App\Catalog\Domain\Category\CategoryRepository;

final class InMemoryCategoryRepository implements CategoryRepository
{
    /** @var array<string, Category> */
    private array $categories = [];

    /** How many reads (get, all) since the test last reset it. */
    public int $reads = 0;

    /** @var list<string> the categories locked by lock(), in order */
    public array $lockedRows = [];

    /** @var list<string> the categories locked by lockForAssignment(), in order */
    public array $assignmentLocks = [];

    public function save(Category $category): void
    {
        $this->categories[$category->id()->toString()] = self::copy($category);
    }

    public function saveAll(array $categories): void
    {
        foreach ($categories as $category) {
            $this->save($category);
        }
    }

    public function get(CategoryId $id): Category
    {
        ++$this->reads;

        return isset($this->categories[$id->toString()]) ? self::copy($this->categories[$id->toString()]) : throw new CategoryNotFound();
    }

    public function all(): array
    {
        ++$this->reads;

        return array_values(array_map(self::copy(...), $this->categories));
    }

    public function count(): int
    {
        return \count($this->categories);
    }

    public function movePaths(CategoryPath $from, CategoryPath $to): void
    {
        $prefix = $from->toString();
        foreach ($this->categories as $key => $category) {
            $path = $category->path()->toString();
            if (!str_starts_with($path, $prefix)) {
                continue;
            }
            $newPath = CategoryPath::fromString($to->toString().substr($path, \strlen($prefix)));
            $ancestorIds = $newPath->ancestorIds();
            $this->categories[$key] = Category::reconstitute($category->id(), $category->name(), $category->slug(), $ancestorIds[\count($ancestorIds) - 2] ?? null, $newPath, $category->position(), $category->version());
        }
    }

    public function lock(CategoryId $id): void
    {
        $this->lockedRows[] = $id->toString();
    }

    public function lockForAssignment(CategoryId $id): void
    {
        isset($this->categories[$id->toString()]) || throw new CategoryNotFound();
        $this->assignmentLocks[] = $id->toString();
    }

    public function remove(Category $category): void
    {
        unset($this->categories[$category->id()->toString()]);
    }

    /**
     * The stored state is never the object a test or a handler goes on changing.
     */
    private static function copy(Category $category): Category
    {
        return Category::reconstitute($category->id(), $category->name(), $category->slug(), $category->parentId(), $category->path(), $category->position(), $category->version());
    }
}
