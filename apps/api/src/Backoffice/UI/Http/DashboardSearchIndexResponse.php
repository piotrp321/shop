<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\SearchIndexStatus;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The search index compared with the catalogue.', required: ['indexedProducts', 'catalogProducts', 'inSync'])]
final readonly class DashboardSearchIndexResponse
{
    public function __construct(
        #[OA\Property(description: 'Documents in the index. Null when the index cannot be counted.', example: 412, nullable: true)]
        public ?int $indexedProducts,
        #[OA\Property(description: 'Products in the database.', example: 412)]
        public int $catalogProducts,
        #[OA\Property(description: 'Whether both numbers are equal. False when the index cannot be counted.', example: true)]
        public bool $inSync,
    ) {
    }

    public static function of(SearchIndexStatus $searchIndex): self
    {
        return new self($searchIndex->indexedProducts, $searchIndex->catalogProducts, $searchIndex->inSync);
    }
}
