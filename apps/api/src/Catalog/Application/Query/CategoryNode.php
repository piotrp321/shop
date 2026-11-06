<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class CategoryNode
{
    /**
     * @param string             $path     the slugs from the root to this category joined with `/`, e.g. `turystyka/biwak/namioty`
     * @param list<CategoryNode> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $path,
        public array $children,
    ) {
    }
}
