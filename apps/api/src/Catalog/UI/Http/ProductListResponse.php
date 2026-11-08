<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\BackofficeProductList;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the product list of the panel.', required: ['items', 'next'])]
final readonly class ProductListResponse
{
    /**
     * @param list<ProductListItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ProductListItemResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after`), null on the last page.', nullable: true, example: 'eyJuIjoiQmlkb24iLCJpIjoiMDE5MmYwYzQtN2MxYS03YzRlLTlhNmUtM2YxZDJiNGM1YTYwIn0')]
        public ?string $next,
    ) {
    }

    public static function of(BackofficeProductList $list): self
    {
        return new self(array_map(ProductListItemResponse::of(...), $list->items), $list->next);
    }
}
