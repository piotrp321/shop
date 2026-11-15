<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class CatalogSection
{
    /**
     * @param ?int $estimatedProductCount the estimate of the number of products, null while the database has none
     */
    public function __construct(
        public ?int $estimatedProductCount,
        public int $categoryCount,
    ) {
    }
}
