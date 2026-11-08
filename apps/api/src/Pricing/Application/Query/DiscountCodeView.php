<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\DiscountCode\DiscountKind;

/**
 * A discount code for the panel, with its status at the time of the read and the number of its active usages (Q5).
 */
final readonly class DiscountCodeView
{
    /**
     * @param int $value the percent, or the amount in grosze
     */
    public function __construct(
        public string $id,
        public string $code,
        public DiscountKind $kind,
        public int $value,
        public ?int $minimumOrderGross,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public ?int $usageLimit,
        public int $activeUses,
        public bool $enabled,
        public DiscountCodeStatus $status,
        public int $version,
    ) {
    }
}
