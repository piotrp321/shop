<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Application\Query\DiscountCodeList;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the discount codes, newest first.', required: ['items', 'next'])]
final readonly class DiscountCodeListResponse
{
    /**
     * @param list<DiscountCodeResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: DiscountCodeResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after`), null on the last page.', nullable: true)]
        public ?string $next,
    ) {
    }

    public static function of(DiscountCodeList $list): self
    {
        return new self(array_map(DiscountCodeResponse::of(...), $list->items), $list->next);
    }
}
