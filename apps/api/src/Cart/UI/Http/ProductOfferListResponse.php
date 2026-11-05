<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'One offer per requested product, in the order of the request; a repeated id answers once.', required: ['items'])]
final readonly class ProductOfferListResponse
{
    /**
     * @param list<ProductOfferResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ProductOfferResponse::class)))]
        public array $items,
    ) {
    }
}
