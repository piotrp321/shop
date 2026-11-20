<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Application\Query\DeliveryMethodView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'Every delivery method, without pages: the active ones by position, then by name; then the inactive ones by name.', required: ['items'])]
final readonly class DeliveryMethodListResponse
{
    /**
     * @param list<DeliveryMethodResponse> $items
     */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: DeliveryMethodResponse::class)))]
        public array $items,
    ) {
    }

    /**
     * @param list<DeliveryMethodView> $methods
     */
    public static function of(array $methods): self
    {
        return new self(array_map(DeliveryMethodResponse::of(...), $methods));
    }
}
