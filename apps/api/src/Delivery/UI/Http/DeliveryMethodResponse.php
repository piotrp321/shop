<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Application\Query\DeliveryMethodView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A delivery method of the panel. Amounts in grosze.', required: ['id', 'name', 'description', 'priceGross', 'freeFromGross', 'active', 'position', 'trackingUrlTemplate', 'version'])]
final readonly class DeliveryMethodResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Kurier standardowy')]
        public string $name,
        #[OA\Property(description: 'Empty without a description.', example: 'Dostawa w 2–3 dni robocze')]
        public string $description,
        #[OA\Property(description: 'Gross price.', example: 1999)]
        public int $priceGross,
        #[OA\Property(description: 'Free from this value of the goods after discounts (gross); null without a threshold.', nullable: true, example: 29900)]
        public ?int $freeFromGross,
        #[OA\Property(example: true)]
        public bool $active,
        #[OA\Property(description: 'Ascending position among the active methods.', example: 10)]
        public int $position,
        #[OA\Property(description: 'Null without a template.', nullable: true, example: 'https://tracking.example.com/{numer}')]
        public ?string $trackingUrlTemplate,
        #[OA\Property(description: 'Version of the method, for the optimistic locking of changes.', example: 1)]
        public int $version,
    ) {
    }

    public static function of(DeliveryMethodView $method): self
    {
        return new self(
            $method->id,
            $method->name,
            $method->description,
            $method->priceGross,
            $method->freeFromGross,
            $method->active,
            $method->position,
            $method->trackingUrlTemplate,
            $method->version,
        );
    }
}
