<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryTree;
use App\Shared\Domain\BoundedCount;

/**
 * The category tree as nodes with slug and path, siblings in the order of {@see CategoryTree}: position, then name
 * (Polish collation), then id. With every position 0 (until a staff member orders a level) the order is by name.
 */
final readonly class CategoryTreeBuilder
{
    /**
     * @param list<Category> $categories every category, in any order
     *
     * @return list<CategoryNode> the roots
     */
    public static function build(array $categories): array
    {
        return self::map(
            CategoryTree::of($categories),
            static fn (Category $category, string $path, array $children): CategoryNode => new CategoryNode(
                $category->id()->toString(),
                $category->name()->value(),
                $category->slug()->value(),
                $path,
                $children,
            ),
        );
    }

    /**
     * The tree of the panel: the nodes of the store with the version of every category and, when counted, its direct
     * products.
     *
     * @param list<Category>                   $categories    every category, in any order
     * @param array<string, BoundedCount>|null $productCounts category id => its products (none: 0), null when not counted
     *
     * @return list<BackofficeCategoryNode> the roots
     */
    public static function buildBackoffice(array $categories, ?array $productCounts): array
    {
        return self::map(
            CategoryTree::of($categories),
            static fn (Category $category, string $path, array $children): BackofficeCategoryNode => new BackofficeCategoryNode(
                $category->id()->toString(),
                $category->name()->value(),
                $category->slug()->value(),
                $path,
                $category->version(),
                null === $productCounts ? null : $productCounts[$category->id()->toString()] ?? new BoundedCount(0, false),
                $children,
            ),
        );
    }

    /**
     * @template T
     *
     * @param \Closure(Category, string, list<T>): T $node the node of a category from its path and its child nodes
     *
     * @return list<T>
     */
    private static function map(CategoryTree $tree, \Closure $node, ?Category $parent = null, string $parentPath = ''): array
    {
        return array_map(
            static function (Category $category) use ($tree, $node, $parentPath): mixed {
                $path = '' === $parentPath ? $category->slug()->value() : $parentPath.'/'.$category->slug()->value();

                return $node($category, $path, self::map($tree, $node, $category, $path));
            },
            $tree->children($parent?->id()),
        );
    }
}
