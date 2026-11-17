<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\CatalogSection;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Catalogue numbers, from the database.', required: ['productCount', 'categoryCount'])]
final readonly class DashboardCatalogResponse
{
    public function __construct(
        #[OA\Property(
            description: 'The estimated number of products. Null while the database has no statistics of the products yet (they are refreshed after a mass change and by the autovacuum).',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: DashboardProductCountResponse::class))],
        )]
        public ?DashboardProductCountResponse $productCount,
        #[OA\Property(example: 36)]
        public int $categoryCount,
    ) {
    }

    public static function of(CatalogSection $catalog): self
    {
        return new self(
            null === $catalog->estimatedProductCount ? null : new DashboardProductCountResponse($catalog->estimatedProductCount),
            $catalog->categoryCount,
        );
    }
}
