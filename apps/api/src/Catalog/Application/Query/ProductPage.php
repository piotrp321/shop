<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

final readonly class ProductPage
{
    /**
     * @param list<ProductListItem> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ProductListItem::class)))]
        public array $items,
        #[OA\Property(description: 'The requested page, from 1.', example: 1)]
        public int $page,
        #[OA\Property(description: 'The requested page size.', example: 24)]
        public int $perPage,
        #[OA\Property(description: 'All products matching the filter, not only this page.', example: 412)]
        public int $total,
    ) {
    }
}
