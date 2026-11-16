<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

/**
 * The old addresses of categories (spec ADMIN-FEATURES, 4 "Przekierowania"): an address a category or one of its
 * ancestors had before a change of a slug or a move leads to the category, wherever it is now. A deleted category
 * takes its redirects with it.
 */
interface CategoryRedirectRepository
{
    /**
     * @param array<string, CategoryId> $redirects old address (slug path) => the category it leads to; an address
     *                                             recorded before leads to the new category
     */
    public function record(array $redirects): void;

    /**
     * An address of an existing category is no redirect: the category wins.
     *
     * @param list<string> $paths
     */
    public function forget(array $paths): void;

    public function find(CategorySlugPath $path): ?CategoryId;
}
