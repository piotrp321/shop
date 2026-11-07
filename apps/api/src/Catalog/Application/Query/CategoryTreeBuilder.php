<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Category\Category;

/**
 * The category tree as nodes with slug and path, siblings ordered by name (Polish collation), then by id.
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
        $roots = [];
        $childrenByParent = [];
        foreach ($categories as $category) {
            $parentId = $category->parentId();
            if (null === $parentId) {
                $roots[] = $category;
            } else {
                $childrenByParent[$parentId->toString()][] = $category;
            }
        }

        return self::nodes($roots, $childrenByParent, new \Collator('pl_PL'), '');
    }

    /**
     * @param list<Category>                $siblings
     * @param array<string, list<Category>> $childrenByParent
     * @param string                        $parentPath       the path of the parent of the siblings, empty for the roots
     *
     * @return list<CategoryNode>
     */
    private static function nodes(array $siblings, array $childrenByParent, \Collator $collator, string $parentPath): array
    {
        usort($siblings, static fn (Category $a, Category $b): int => $collator->compare($a->name()->value(), $b->name()->value())
            ?: strcmp($a->id()->toString(), $b->id()->toString()));

        return array_map(
            static function (Category $category) use ($childrenByParent, $collator, $parentPath): CategoryNode {
                $slug = $category->slug()->value();
                $path = '' === $parentPath ? $slug : $parentPath.'/'.$slug;

                return new CategoryNode(
                    $category->id()->toString(),
                    $category->name()->value(),
                    $slug,
                    $path,
                    self::nodes($childrenByParent[$category->id()->toString()] ?? [], $childrenByParent, $collator, $path),
                );
            },
            $siblings,
        );
    }
}
