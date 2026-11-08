<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Application\Query\DiscountCodeView;
use App\Pricing\UI\Http\Promotion\PromotionListItemResponse;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A discount code. Times in UTC (RFC 3339), whole seconds; the code is valid in [startsAt, endsAt).', required: ['id', 'code', 'kind', 'value', 'minimumOrderGross', 'startsAt', 'endsAt', 'usageLimit', 'activeUses', 'enabled', 'status', 'version'])]
final readonly class DiscountCodeResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(description: 'Upper case.', example: 'WITAJ10')]
        public string $code,
        #[OA\Property(enum: DiscountCodeBodyRules::KINDS, example: 'percent')]
        public string $kind,
        #[OA\Property(description: 'The percent (1 to 90) for `percent`, the amount in grosze (100 to 1 000 000) for `amount`.', example: 10)]
        public int $value,
        #[OA\Property(description: 'In grosze; null for none.', nullable: true, example: null)]
        public ?int $minimumOrderGross,
        #[OA\Property(format: 'date-time', example: '2026-11-10T23:00:00+00:00')]
        public string $startsAt,
        #[OA\Property(format: 'date-time', description: 'Null: no end.', nullable: true, example: null)]
        public ?string $endsAt,
        #[OA\Property(description: 'Null: no limit.', nullable: true, example: 100)]
        public ?int $usageLimit,
        #[OA\Property(description: 'The usages of orders that are not cancelled unpaid.', example: 3)]
        public int $activeUses,
        #[OA\Property(example: true)]
        public bool $enabled,
        #[OA\Property(description: 'From the time of the server: disabled (whatever the period), planned before the start, active until before the end, expired after.', enum: ['disabled', 'planned', 'active', 'expired'])]
        public string $status,
        #[OA\Property(description: 'Version of the code, for the optimistic locking of changes.', example: 1)]
        public int $version,
    ) {
    }

    public static function of(DiscountCodeView $code): self
    {
        return new self(
            $code->id,
            $code->code,
            $code->kind->value,
            $code->value,
            $code->minimumOrderGross,
            PromotionListItemResponse::moment($code->startsAt),
            null === $code->endsAt ? null : PromotionListItemResponse::moment($code->endsAt),
            $code->usageLimit,
            $code->activeUses,
            $code->enabled,
            $code->status->value,
            $code->version,
        );
    }
}
