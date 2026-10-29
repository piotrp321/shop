<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class ListProducts
{
    /**
     * @param string|null $categoryId filters by the category and all its descendants
     * @param int         $page       from 1
     * @param int         $perPage    from 1
     */
    public function __construct(
        public ?string $categoryId,
        public int $page,
        public int $perPage,
    ) {
    }
}
