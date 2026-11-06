<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

final class Category
{
    private function __construct(
        private readonly CategoryId $id,
        private readonly CategoryName $name,
        private readonly CategorySlug $slug,
        private readonly ?CategoryId $parentId,
        private readonly CategoryPath $path,
    ) {
    }

    /**
     * The uniqueness of the slug among the siblings is checked by CreateCategory, which sees the siblings.
     */
    public static function createRoot(CategoryId $id, CategoryName $name, CategorySlug $slug): self
    {
        return new self($id, $name, $slug, null, CategoryPath::root($id));
    }

    public static function createChild(CategoryId $id, CategoryName $name, CategorySlug $slug, self $parent): self
    {
        return new self($id, $name, $slug, $parent->id, $parent->path->child($id));
    }

    public static function reconstitute(CategoryId $id, CategoryName $name, CategorySlug $slug, ?CategoryId $parentId, CategoryPath $path): self
    {
        $ancestorIds = $path->ancestorIds();
        if (!$id->equals($ancestorIds[\count($ancestorIds) - 1])) {
            throw new \InvalidArgumentException('CategoryPath must end with the category id.');
        }

        $expectedParentId = $ancestorIds[\count($ancestorIds) - 2] ?? null;
        $parentMatches = null === $parentId || null === $expectedParentId
            ? $parentId === $expectedParentId
            : $parentId->equals($expectedParentId);
        if (!$parentMatches) {
            throw new \InvalidArgumentException('Category parent must be the penultimate CategoryPath element.');
        }

        return new self($id, $name, $slug, $parentId, $path);
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function name(): CategoryName
    {
        return $this->name;
    }

    public function slug(): CategorySlug
    {
        return $this->slug;
    }

    public function parentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function path(): CategoryPath
    {
        return $this->path;
    }
}
