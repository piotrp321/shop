<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

use App\Shared\Domain\BoundedCount;

/**
 * Every category at once, and the rules between them (spec ADMIN-FEATURES, 4 "Reguły" and "Przekierowania", decision
 * P9): a slug unique among the siblings, at most {@see MAX_DEPTH} levels, no cycle, only an empty category deleted, the
 * version a change is based on, the place among the siblings and the addresses a change moves.
 *
 * The siblings are ordered by position, then by name in Polish collation, then by id. Until a staff member orders
 * them every position is 0, so the order is the alphabetical one. Placing a category (a new one, a moved one, a new
 * order) first numbers the target siblings 0, 1, 2… in their current order and then puts the category at its place,
 * so the others keep their relative order.
 *
 * The tree is small (decision P9: a few hundred categories), so a change reads all of it. A change mutates the
 * categories it returns; the tree itself is meant for one change.
 */
final class CategoryTree
{
    public const int MAX_DEPTH = 4;

    /** @var array<string, list<Category>> parent id ('' for the roots) => children in sibling order */
    private array $children = [];

    /**
     * @param array<string, Category> $categories id => category
     */
    private function __construct(private array $categories, private readonly \Collator $collator)
    {
        $this->index();
    }

    /**
     * @param list<Category> $categories every category, in any order
     */
    public static function of(array $categories): self
    {
        $byId = [];
        foreach ($categories as $category) {
            $byId[$category->id()->toString()] = $category;
        }

        return new self($byId, new \Collator('pl_PL'));
    }

    /**
     * @throws CategoryNotFound
     */
    public function get(CategoryId $id): Category
    {
        return $this->categories[$id->toString()] ?? throw new CategoryNotFound();
    }

    /**
     * @return list<Category>
     */
    public function all(): array
    {
        return array_values($this->categories);
    }

    /**
     * @return list<Category> the children of the parent (the roots for null) in sibling order
     */
    public function children(?CategoryId $parentId): array
    {
        return $this->children[$parentId?->toString() ?? ''] ?? [];
    }

    /**
     * The address of the category in the store.
     *
     * @throws CategoryNotFound
     */
    public function slugPath(CategoryId $id): CategorySlugPath
    {
        $ancestorIds = $this->get($id)->path()->ancestorIds();
        $path = CategorySlugPath::root($this->get($ancestorIds[0])->slug());
        foreach (\array_slice($ancestorIds, 1) as $descendantId) {
            $path = $path->child($this->get($descendantId)->slug());
        }

        return $path;
    }

    /**
     * A new category. The panel places it at the end of its siblings; the seed (`append: false`) leaves it at
     * position 0 and the siblings as they are, so the demo tree keeps the alphabetical order.
     *
     * @throws CategoryAlreadyExists
     * @throws ParentCategoryNotFound
     * @throws CategoryTooDeep        when the parent is on the last level
     * @throws CategorySlugTaken
     */
    public function add(CategoryId $id, CategoryName $name, CategorySlug $slug, ?CategoryId $parentId, bool $append = true): CategoryTreeChange
    {
        if (isset($this->categories[$id->toString()])) {
            throw new CategoryAlreadyExists();
        }
        $parent = $this->parent($parentId);
        if (null !== $parent && $parent->depth() >= self::MAX_DEPTH) {
            throw new CategoryTooDeep();
        }
        $this->assertSlugFree($parentId, $slug, null);

        $category = null === $parent ? Category::createRoot($id, $name, $slug) : Category::createChild($id, $name, $slug, $parent);
        $siblings = $append ? $this->renumber($this->children($parentId)) : [];
        if ($append) {
            $category->placeAt(\count($this->children($parentId)));
        }
        $this->categories[$id->toString()] = $category;
        $this->index();

        return new CategoryTreeChange($category, [$category, ...$siblings], restoredPaths: [$this->slugPath($id)->toString()]);
    }

    /**
     * A new name and slug. A new slug changes the addresses of the category and of its descendants: the old ones
     * become redirects.
     *
     * @throws CategoryNotFound
     * @throws CategoryModifiedConcurrently
     * @throws CategorySlugTaken
     */
    public function revise(CategoryId $id, int $expectedVersion, CategoryName $name, CategorySlug $slug): CategoryTreeChange
    {
        $category = $this->get($id);
        $category->assertVersion($expectedVersion);
        $this->assertSlugFree($category->parentId(), $slug, $id);

        $before = $this->addresses($id);
        $category->revise($name, $slug);

        return $this->changed($category, [$category], null, $before);
    }

    /**
     * Under another parent (null: a root) or the same one, at a position among the new siblings (0 is the first; a
     * position past the last sibling is the end). A new parent changes the addresses of the category and its
     * descendants: the old ones become redirects.
     *
     * @throws CategoryNotFound
     * @throws CategoryModifiedConcurrently
     * @throws ParentCategoryNotFound
     * @throws CategoryCycle                when the parent is the category or one of its descendants
     * @throws CategoryTooDeep              when the deepest descendant would end below the last level
     * @throws CategorySlugTaken            when a child of the new parent has the slug
     */
    public function move(CategoryId $id, int $expectedVersion, ?CategoryId $parentId, int $position): CategoryTreeChange
    {
        if ($position < 0) {
            throw new \InvalidArgumentException('A category position cannot be negative.');
        }
        $category = $this->get($id);
        $category->assertVersion($expectedVersion);
        $parent = $this->parent($parentId);
        if (null !== $parent && str_starts_with($parent->path()->toString(), $category->path()->toString())) {
            throw new CategoryCycle();
        }
        $sameParent = null === $parentId ? null === $category->parentId() : null !== $category->parentId() && $category->parentId()->equals($parentId);
        if (!$sameParent) {
            if (($parent?->depth() ?? 0) + $this->height($category) > self::MAX_DEPTH) {
                throw new CategoryTooDeep();
            }
            $this->assertSlugFree($parentId, $category->slug(), $id);
        }

        $before = $sameParent ? [] : $this->addresses($id);
        $previousPath = $category->path();
        $siblings = array_values(array_filter($this->children($parentId), static fn (Category $sibling): bool => !$sibling->id()->equals($id)));
        $position = min($position, \count($siblings));
        array_splice($siblings, $position, 0, [$category]);
        $renumbered = array_values(array_filter($this->renumber($siblings), static fn (Category $sibling): bool => !$sibling->id()->equals($id)));
        $category->moveUnder($parent, $position);
        $this->moveDescendants($previousPath, $category->path());

        return $this->changed($category, [$category, ...$renumbered], $sameParent ? null : $previousPath, $before);
    }

    /**
     * @param BoundedCount $products the products of the category, main or additional, drafts too
     *
     * @throws CategoryNotFound
     * @throws CategoryModifiedConcurrently
     * @throws CategoryNotEmpty             when the category has subcategories or products
     */
    public function remove(CategoryId $id, int $expectedVersion, BoundedCount $products): Category
    {
        $category = $this->get($id);
        $category->assertVersion($expectedVersion);
        $subcategories = \count($this->children($id));
        if ($subcategories > 0 || $products->value > 0) {
            throw new CategoryNotEmpty(new BoundedCount($subcategories, false), $products);
        }
        unset($this->categories[$id->toString()]);
        $this->index();

        return $category;
    }

    /**
     * @throws ParentCategoryNotFound
     */
    private function parent(?CategoryId $parentId): ?Category
    {
        if (null === $parentId) {
            return null;
        }

        return $this->categories[$parentId->toString()] ?? throw new ParentCategoryNotFound();
    }

    /**
     * @throws CategorySlugTaken
     */
    private function assertSlugFree(?CategoryId $parentId, CategorySlug $slug, ?CategoryId $except): void
    {
        foreach ($this->children($parentId) as $sibling) {
            if ($sibling->slug()->equals($slug) && (null === $except || !$sibling->id()->equals($except))) {
                throw new CategorySlugTaken();
            }
        }
    }

    /**
     * The categories numbered 0, 1, 2… in the order given.
     *
     * @param list<Category> $siblings
     *
     * @return list<Category> those whose position changed
     */
    private function renumber(array $siblings): array
    {
        $changed = [];
        foreach ($siblings as $position => $sibling) {
            if ($sibling->position() !== $position) {
                $sibling->placeAt($position);
                $changed[] = $sibling;
            }
        }

        return $changed;
    }

    /**
     * The levels of the category and its deepest descendant, 1 for a leaf.
     */
    private function height(Category $category): int
    {
        $height = 1;
        foreach ($this->subtree($category) as $descendant) {
            $height = max($height, $descendant->depth() - $category->depth() + 1);
        }

        return $height;
    }

    /**
     * @return list<Category> the category and all its descendants
     */
    private function subtree(Category $category): array
    {
        $prefix = $category->path()->toString();

        return array_values(array_filter($this->categories, static fn (Category $candidate): bool => str_starts_with($candidate->path()->toString(), $prefix)));
    }

    /**
     * @return array<string, CategoryId> the address of the category and of each descendant => its id
     */
    private function addresses(CategoryId $id): array
    {
        $addresses = [];
        foreach ($this->subtree($this->get($id)) as $category) {
            $addresses[$this->slugPath($category->id())->toString()] = $category->id();
        }

        return $addresses;
    }

    /**
     * Gives the descendants of a moved category the paths they have after the move, so that the addresses of the
     * subtree are computed on the new tree (the repository changes them in the database with one statement).
     */
    private function moveDescendants(CategoryPath $from, CategoryPath $to): void
    {
        $prefix = $from->toString();
        foreach ($this->categories as $key => $candidate) {
            $path = $candidate->path()->toString();
            if ($path === $prefix || !str_starts_with($path, $prefix)) {
                continue;
            }
            $newPath = CategoryPath::fromString($to->toString().substr($path, \strlen($prefix)));
            $ancestorIds = $newPath->ancestorIds();
            $this->categories[$key] = Category::reconstitute(
                $candidate->id(),
                $candidate->name(),
                $candidate->slug(),
                $ancestorIds[\count($ancestorIds) - 2] ?? null,
                $newPath,
                $candidate->position(),
                $candidate->version(),
            );
        }
    }

    /**
     * @param list<Category>            $saved
     * @param array<string, CategoryId> $before the addresses of the subtree before the change
     */
    private function changed(Category $category, array $saved, ?CategoryPath $previousPath, array $before): CategoryTreeChange
    {
        $this->index();
        if ([] === $before) {
            return new CategoryTreeChange($category, $saved, $previousPath);
        }
        $after = array_keys($this->addresses($category->id()));
        if ($after === array_keys($before)) {
            return new CategoryTreeChange($category, $saved, $previousPath);
        }

        return new CategoryTreeChange($category, $saved, $previousPath, $before, $after);
    }

    private function index(): void
    {
        $children = [];
        foreach ($this->categories as $category) {
            $children[$category->parentId()?->toString() ?? ''][] = $category;
        }
        foreach ($children as $key => $siblings) {
            usort($siblings, $this->compare(...));
            $children[$key] = $siblings;
        }
        $this->children = $children;
    }

    private function compare(Category $a, Category $b): int
    {
        return $a->position() <=> $b->position()
            ?: $this->collator->compare($a->name()->value(), $b->name()->value())
            ?: strcmp($a->id()->toString(), $b->id()->toString());
    }
}
