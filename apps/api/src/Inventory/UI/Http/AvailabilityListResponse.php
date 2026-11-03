<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The availability levels of the requested products.', required: ['items'])]
final readonly class AvailabilityListResponse
{
    /**
     * @param list<AvailabilityItemResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: AvailabilityItemResponse::class)))]
        public array $items,
    ) {
    }
}
