<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

/**
 * A category of the tree. The rules between categories (unique slug among the siblings, the place among them, the
 * depth, cycles, empty deletion) are checked by {@see CategoryTree}, which sees all of them.
 *
 * `version` guards the name, the slug and the parent: every change of the category itself (a revision, a move, also
 * within the same parent) raises it. The position among the siblings changes without it when another category is
 * placed between them.
 */
final class Category
{
    private function __construct(
        private readonly CategoryId $id,
        private CategoryName $name,
        private CategorySlug $slug,
        private ?CategoryId $parentId,
        private CategoryPath $path,
        private int $position,
        private int $version,
    ) {
        if ($position < 0) {
            throw new \InvalidArgumentException('A category position cannot be negative.');
        }
        if ($version < 1) {
            throw new \InvalidArgumentException('A category version starts at 1.');
        }
    }

    /**
     * The uniqueness of the slug among the siblings is checked by CategoryTree, which sees the siblings.
     */
    public static function createRoot(CategoryId $id, CategoryName $name, CategorySlug $slug, int $position = 0): self
    {
        return new self($id, $name, $slug, null, CategoryPath::root($id), $position, 1);
    }

    public static function createChild(CategoryId $id, CategoryName $name, CategorySlug $slug, self $parent, int $position = 0): self
    {
        return new self($id, $name, $slug, $parent->id, $parent->path->child($id), $position, 1);
    }

    public static function reconstitute(
        CategoryId $id,
        CategoryName $name,
        CategorySlug $slug,
        ?CategoryId $parentId,
        CategoryPath $path,
        int $position = 0,
        int $version = 1,
    ): self {
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

        return new self($id, $name, $slug, $parentId, $path, $position, $version);
    }

    /**
     * @throws CategoryModifiedConcurrently when the version the change is based on is not this one
     */
    public function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->version) {
            throw new CategoryModifiedConcurrently();
        }
    }

    public function revise(CategoryName $name, CategorySlug $slug): void
    {
        $this->name = $name;
        $this->slug = $slug;
        ++$this->version;
    }

    /**
     * Under another parent (null: a root) or the same one, at a position among its new siblings. The paths of the
     * descendants follow from this path; CategoryTree refuses a parent in the own subtree first.
     */
    public function moveUnder(?self $parent, int $position): void
    {
        $this->parentId = $parent?->id;
        $this->path = null === $parent ? CategoryPath::root($this->id) : $parent->path->child($this->id);
        $this->placeAt($position);
        ++$this->version;
    }

    /**
     * Another place among the same siblings, without a new version: the siblings are renumbered around a category
     * placed between them.
     */
    public function placeAt(int $position): void
    {
        if ($position < 0) {
            throw new \InvalidArgumentException('A category position cannot be negative.');
        }
        $this->position = $position;
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

    public function position(): int
    {
        return $this->position;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * 1 for a root.
     */
    public function depth(): int
    {
        return \count($this->path->ancestorIds());
    }
}
