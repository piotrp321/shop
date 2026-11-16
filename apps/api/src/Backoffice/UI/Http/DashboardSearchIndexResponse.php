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
        #[OA\Property(description: 'The estimated number of products in the database. Null while the database has no statistics of the products yet.', example: 412, nullable: true)]
        public ?int $catalogProducts,
        #[OA\Property(description: 'Whether the index is within 1% of the estimated number of products. False when the index cannot be counted, null without an estimate to compare with.', example: true, nullable: true)]
        public ?bool $inSync,
    ) {
    }

    public static function of(SearchIndexStatus $searchIndex): self
    {
        return new self($searchIndex->indexedProducts, $searchIndex->catalogProducts, $searchIndex->inSync);
    }
}
