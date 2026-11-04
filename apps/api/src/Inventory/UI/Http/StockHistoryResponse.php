<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\StockHistoryPage;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the stock history of a product variant, newest entry first.', required: ['items', 'next'])]
final readonly class StockHistoryResponse
{
    /**
     * @param list<StockHistoryItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: StockHistoryItemResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after`), null on the last page.', nullable: true, example: 'eyJ0IjoiMjAyNi0xMS0wMVQxMjozMDowMC4wMDAwMDArMDA6MDAiLCJpIjoiMDE5MmYwYzQtN2MxYS03YzRlLTlhNmUtM2YxZDJiNGM1YTYwIn0')]
        public ?string $next,
    ) {
    }

    public static function of(StockHistoryPage $page): self
    {
        return new self(array_map(StockHistoryItemResponse::of(...), $page->items), $page->next);
    }
}
