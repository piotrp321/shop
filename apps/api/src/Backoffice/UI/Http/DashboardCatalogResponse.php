<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\CatalogSection;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Catalogue numbers, from the database.')]
final readonly class DashboardCatalogResponse
{
    public function __construct(
        #[OA\Property(example: 412)]
        public int $productCount,
        #[OA\Property(example: 36)]
        public int $categoryCount,
        #[OA\Property(description: 'Products with no image.', example: 8)]
        public int $productsWithoutImages,
    ) {
    }

    public static function of(CatalogSection $catalog): self
    {
        return new self($catalog->productCount, $catalog->categoryCount, $catalog->productsWithoutImages);
    }
}
