<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

final readonly class SitemapProduct
{
    /**
     * @param string $updatedAt time of the last change of the product, ISO 8601 with the offset
     */
    public function __construct(
        public string $slug,
        public string $updatedAt,
    ) {
    }
}
