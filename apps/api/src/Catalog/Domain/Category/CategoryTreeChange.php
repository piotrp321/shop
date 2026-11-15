<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

/**
 * What a change of the tree saves: the category, the siblings whose position changed, and the redirects of the
 * addresses it changed.
 */
final readonly class CategoryTreeChange
{
    /**
     * @param list<Category>            $saved         the category first, then the siblings with a new position
     * @param CategoryPath|null         $previousPath  the path of a moved category before the move; the paths of its
     *                                                 descendants start with it and change with it
     * @param array<string, CategoryId> $redirects     the addresses (slug paths) the category and its descendants had
     *                                                 before the change => the category each one leads to
     * @param list<string>              $restoredPaths the addresses the category and its descendants have now: an
     *                                                 address of an existing category is no redirect
     */
    public function __construct(
        public Category $category,
        public array $saved,
        public ?CategoryPath $previousPath = null,
        public array $redirects = [],
        public array $restoredPaths = [],
    ) {
    }
}
