<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\PromotionListItem;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A promotion of the list. Times in UTC (RFC 3339); the period is [startsAt, endsAt).', required: ['id', 'name', 'percentOff', 'startsAt', 'endsAt', 'status', 'variantCount', 'version'])]
final readonly class PromotionListItemResponse
{
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
        #[OA\Property(description: 'From the time of the server.', enum: ['planned', 'active', 'ended'])]
        public string $status,
        #[OA\Property(example: 2)]
        public int $variantCount,
        #[OA\Property(example: 1)]
        public int $version,
    ) {
    }

    public static function of(PromotionListItem $promotion): self
    {
        return new self(
            $promotion->id,
            $promotion->name,
            $promotion->percentOff,
            self::moment($promotion->startsAt),
            self::moment($promotion->endsAt),
            $promotion->status->value,
            $promotion->variantCount,
            $promotion->version,
        );
    }

    /**
     * A moment of a response: RFC 3339 in UTC.
     */
    public static function moment(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
