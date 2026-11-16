<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\PromotionList;
use App\Shared\UI\Http\BoundedCountResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the promotions, newest start first.', required: ['items', 'next', 'total'])]
final readonly class PromotionListResponse
{
    /**
     * @param list<PromotionListItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: PromotionListItemResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after`), null on the last page.', nullable: true)]
        public ?string $next,
        #[OA\Property(
            description: 'How many promotions match the filter, up to 10 000; on the first page (no `after`) only, null on the next ones.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))],
        )]
        public ?BoundedCountResponse $total,
    ) {
    }

    public static function of(PromotionList $list): self
    {
        return new self(array_map(PromotionListItemResponse::of(...), $list->items), $list->next, BoundedCountResponse::ofNullable($list->total));
    }
}
