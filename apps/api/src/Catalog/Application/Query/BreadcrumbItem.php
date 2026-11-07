<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class BreadcrumbItem
{
    /**
     * @param string $path the slugs from the root to this category joined with `/`, as in CategoryNode
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $path,
    ) {
    }
}
