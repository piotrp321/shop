<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\PromotionDetails;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A promotion with its variants. Times in UTC (RFC 3339), whole seconds; the period is [startsAt, endsAt).', required: ['id', 'name', 'percentOff', 'startsAt', 'endsAt', 'status', 'variantCount', 'version', 'updatedAt', 'updatedByName', 'variants'])]
final readonly class PromotionResponse
{
    /**
     * @param list<PromotionVariantResponse> $variants
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Jesień w górach')]
        public string $name,
        #[OA\Property(example: 20)]
        public int $percentOff,
        #[OA\Property(format: 'date-time', example: '2026-11-10T23:00:00+00:00')]
        public string $startsAt,
        #[OA\Property(format: 'date-time', example: '2026-11-20T23:00:00+00:00')]
        public string $endsAt,
        #[OA\Property(description: 'From the time of the server: planned before the start, active until before the end, ended after.', enum: ['planned', 'active', 'ended'])]
        public string $status,
        #[OA\Property(example: 2)]
        public int $variantCount,
        #[OA\Property(description: 'Version of the promotion, for the optimistic locking of changes.', example: 1)]
        public int $version,
        #[OA\Property(format: 'date-time', description: 'When the promotion was planned or last changed.', example: '2026-11-07T12:30:00+00:00')]
        public string $updatedAt,
        #[OA\Property(description: 'The staff member of the last change; null for the demo data.', nullable: true, example: 'ola.marketing')]
        public ?string $updatedByName,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: PromotionVariantResponse::class)))]
        public array $variants,
    ) {
    }

    public static function of(PromotionDetails $promotion): self
    {
        return new self(
            $promotion->id,
            $promotion->name,
            $promotion->percentOff,
            PromotionListItemResponse::moment($promotion->startsAt),
            PromotionListItemResponse::moment($promotion->endsAt),
            $promotion->status->value,
            $promotion->variantCount,
            $promotion->version,
            PromotionListItemResponse::moment($promotion->updatedAt),
            $promotion->updatedByName,
            array_map(PromotionVariantResponse::of(...), $promotion->variants),
        );
    }
}
