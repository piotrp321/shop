<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class CategoryNode
{
    /**
     * @param list<CategoryNode> $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $children,
    ) {
    }
}
