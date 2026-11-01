<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\PriceList;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the price list.', required: ['items', 'next'])]
final readonly class PriceListResponse
{
    /**
     * @param list<PriceListItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: PriceListItemResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after`), null on the last page.', nullable: true, example: 'eyJuIjoiQmlkb24iLCJpIjoiMDE5MmYwYzQtN2MxYS03YzRlLTlhNmUtM2YxZDJiNGM1YTYwIn0')]
        public ?string $next,
    ) {
    }

    public static function of(PriceList $list): self
    {
        return new self(array_map(PriceListItemResponse::of(...), $list->items), $list->next);
    }
}
