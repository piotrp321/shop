<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\StockList;
use App\Shared\Domain\BoundedCount;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the stock list.', required: ['items', 'next', 'total'])]
final readonly class StockListResponse
{
    /**
     * @param list<StockListItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: StockListItemResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after`), null on the last page.', nullable: true, example: 'eyJuIjoiQmlkb24iLCJpIjoiMDE5MmYwYzQtN2MxYS03YzRlLTlhNmUtM2YxZDJiNGM1YTYwIn0')]
        public ?string $next,
        #[OA\Property(
            description: 'How many products match the filters, up to 10 000; on the first page (no `after`) only, null on the next ones.',
            nullable: true,
            allOf: [new OA\Schema(ref: '#/components/schemas/BoundedCount')],
        )]
        public ?BoundedCount $total,
    ) {
    }

    public static function of(StockList $list): self
    {
        return new self(array_map(StockListItemResponse::of(...), $list->items), $list->next, $list->total);
    }
}
