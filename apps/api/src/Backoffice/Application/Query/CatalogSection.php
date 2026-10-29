<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class CatalogSection
{
    public function __construct(
        public int $productCount,
        public int $categoryCount,
        public int $productsWithoutImages,
    ) {
    }
}
