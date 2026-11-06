<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class CategoryFacet
{
    /**
     * @param string $path  the slugs from the root joined with `/`
     * @param int    $count the products found in the category, with the variant conditions of the search
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $path,
        public int $count,
    ) {
    }
}
