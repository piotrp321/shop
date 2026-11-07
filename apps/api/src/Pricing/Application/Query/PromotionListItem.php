<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\Promotion\PromotionStatus;

final readonly class PromotionListItem
{
    public function __construct(
        public string $id,
        public string $name,
        public int $percentOff,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public PromotionStatus $status,
        public int $variantCount,
        public int $version,
    ) {
    }
}
