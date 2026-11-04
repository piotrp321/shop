<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\PriceHistoryPage;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the price history of a product variant, newest change first.', required: ['items', 'next'])]
final readonly class PriceHistoryResponse
{
    /**
     * @param list<PriceHistoryItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: PriceHistoryItemResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after`), null on the last page.', nullable: true, example: 'eyJ0IjoiMjAyNi0xMS0wMVQxMjozMDowMC4wMDAwMDArMDA6MDAiLCJpIjoiMDE5MmYwYzQtN2MxYS03YzRlLTlhNmUtM2YxZDJiNGM1YTYwIn0')]
        public ?string $next,
    ) {
    }

    public static function of(PriceHistoryPage $page): self
    {
        return new self(array_map(PriceHistoryItemResponse::of(...), $page->items), $page->next);
    }
}
