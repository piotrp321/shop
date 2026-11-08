<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\PromotionList;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the promotions, newest start first.', required: ['items', 'next'])]
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
    ) {
    }

    public static function of(PromotionList $list): self
    {
        return new self(array_map(PromotionListItemResponse::of(...), $list->items), $list->next);
    }
}
