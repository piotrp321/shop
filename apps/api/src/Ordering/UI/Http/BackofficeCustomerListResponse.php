<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\BackofficeCustomerList;
use App\Shared\UI\Http\BoundedCountResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A page of the customer list.', required: ['items', 'nextCursor', 'total'])]
final readonly class BackofficeCustomerListResponse
{
    /**
     * @param list<BackofficeCustomerListItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: BackofficeCustomerListItemResponse::class)))]
        public array $items,
        #[OA\Property(description: 'Opaque cursor of the next page (send it as `after` with the same `q` and `sort`), null on the last page.', nullable: true, example: 'eyJzIjoibmFtZSIsInYiOiJhbm5hIG5vd2FrIiwiaSI6ImtjLTEifQ')]
        public ?string $nextCursor,
        #[OA\Property(
            description: 'How many customers match the search, up to 10 000; on the first page (no `after`) only, null on the next ones.',
            nullable: true,
            allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))],
        )]
        public ?BoundedCountResponse $total,
    ) {
    }

    public static function of(BackofficeCustomerList $list): self
    {
        return new self(array_map(BackofficeCustomerListItemResponse::of(...), $list->items), $list->nextCursor, BoundedCountResponse::ofNullable($list->total));
    }
}
