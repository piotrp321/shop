<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\Promotion\PromotionStatus;

final readonly class PromotionDetails
{
    /**
     * @param list<PromotionVariantView> $variants      in the order of the promotion
     * @param string|null                $updatedByName null for a change of the system (the demo data)
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $percentOff,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public PromotionStatus $status,
        public int $variantCount,
        public int $version,
        public \DateTimeImmutable $updatedAt,
        public ?string $updatedByName,
        public array $variants,
    ) {
    }
}
